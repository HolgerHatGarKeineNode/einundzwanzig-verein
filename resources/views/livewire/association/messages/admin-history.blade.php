<?php

use App\Enums\DmDeliveryStatus;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Livewire\Traits\WithMessageDates;
use App\Livewire\Traits\WithViewer;
use App\Models\DmDelivery;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Services\Dm\DirectMessageAudience;
use App\Services\Dm\DirectMessagePause;
use App\Services\Nostr\RelayPublishStatus;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Verlauf" of the board's message centre: the planned rows, then what went
 * out and to whom, one run opened with its state per recipient and the
 * re-send of its failed work.
 *
 * A child of `association.messages.admin`, mounted while that tab is open;
 * the page hands in the run to open (a sending it just started). Board only
 * and callable on its own like every Livewire component: every public
 * method and every computed property resolves the acting board member from
 * the session and answers 403 to anybody else. Opening or closing a run is
 * handed to the page as `message-run-toggled`, whose header keeps one
 * primary button.
 */
new class extends Component
{
    use WithMessageDates;
    use WithViewer;

    /** The runs the list shows; the page counts the failed ones among the same. */
    private const HISTORY_LIMIT = 30;

    #[Locked]
    public ?int $openRunId = null;

    public function mount(?int $openRunId = null): void
    {
        $this->boardActor();

        $this->openRunId = $openRunId;
    }

    public function toggleRun(int $runId): void
    {
        $this->boardActor();

        $this->openRunId = $this->openRunId === $runId ? null : DmRun::query()->findOrFail($runId)->id;
        unset($this->openRun);
        $this->dispatch('message-run-toggled', runId: $this->openRunId);
    }

    /**
     * Hand the run back to the fan-out. The count the board sees and is told
     * is the failed work: `failed` and a claim of a worker that died (shown
     * as failed, see DmDelivery::isStuck). The fan-out also queues `pending`
     * deliveries again — they would go out anyway, and their claim turns the
     * second job into a no-op. `sent` and `no_inbox` are never touched.
     */
    public function resendFailed(int $runId, DirectMessagePause $pause): void
    {
        $this->boardActor();
        $run = DmRun::query()->findOrFail($runId);

        if ($pause->isPaused()) {
            Flux::toast(__('Der Versand ist pausiert. Setze ihn im Zeitplan fort, dann geht es weiter.'), variant: 'danger');

            return;
        }

        $retry = $run->deliveries()->failedOrStuck(now())->count();

        if ($retry === 0) {
            Flux::toast(__('In dieser Nachricht ist nichts fehlgeschlagen.'));

            return;
        }

        DispatchBulkDirectMessages::dispatch($run->id);

        unset($this->runs, $this->openRun);
        Flux::toast(trans_choice('Eine fehlgeschlagene Zustellung wird erneut gesendet.|:count fehlgeschlagene Zustellungen werden erneut gesendet.', $retry, ['count' => $retry]), variant: 'success');
    }

    /**
     * The latest runs with their counts per state.
     *
     * @return EloquentCollection<int, DmRun>
     */
    #[Computed]
    public function runs(): EloquentCollection
    {
        $this->boardActor();

        return DmRun::query()
            ->with(['message.author.profile', 'election'])
            ->withCount([
                'deliveries as sent_count' => fn ($query) => $query->where('status', DmDeliveryStatus::Sent->value),
                'deliveries as no_inbox_count' => fn ($query) => $query->where('status', DmDeliveryStatus::NoInbox->value),
                'deliveries as failed_count' => fn ($query) => $query->failedOrStuck(now()),
                'deliveries as seen_count' => fn ($query) => $query->whereNotNull('seen_at'),
            ])
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get();
    }

    /**
     * Planned rows that have not gone out yet, soonest first.
     *
     * @return EloquentCollection<int, DmSchedule>
     */
    #[Computed]
    public function upcoming(): EloquentCollection
    {
        $this->boardActor();

        return DmSchedule::query()
            ->with(['message', 'election'])
            ->whereNull('dm_run_id')
            ->orderBy('send_at')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function openRun(): ?DmRun
    {
        $this->boardActor();

        return $this->openRunId === null ? null : DmRun::query()
            ->with(['deliveries' => fn ($query) => $query->with(['recipient.profile', 'relays'])->orderBy('id')])
            ->find($this->openRunId);
    }

    /**
     * To whom a run went, as one sentence.
     */
    protected function recipientsOf(DmRun $run): string
    {
        if ($run->audience !== null) {
            if ($run->audience->needsElection() && $run->election === null) {
                return trans_choice(':count Empfänger: :audience einer inzwischen gelöschten Wahl|:count Empfänger: :audience einer inzwischen gelöschten Wahl', $run->recipient_count, ['audience' => $run->audience->label()]);
            }

            return app(DirectMessageAudience::class)->describe($run->audience, $run->election, $run->recipient_count);
        }

        $authorId = $run->message?->author_pleb_id;

        if ($run->recipient_count === 1 && $authorId !== null && $run->recipient_ids === [(int) $authorId]) {
            return __('Test an :name', ['name' => MemberProposal::authorNameOf($run->message->author)]);
        }

        return trans_choice(':count Empfänger (Zielgruppe nicht gespeichert)|:count Empfänger (Zielgruppe nicht gespeichert)', $run->recipient_count);
    }

    /**
     * The four numbers of a run. Failed includes stuck claims
     * (DmDelivery::isStuck), and it is what a re-send picks up. Waiting
     * covers deliveries in work and recipients the fan-out has not reached
     * yet.
     *
     * Skipped are the members the repeat lock left out (DirectMessageRepeatLock):
     * they got no delivery in this run.
     *
     * @return array{sent: int, no_inbox: int, failed: int, waiting: int, seen: int, retry: int, skipped: int}
     */
    protected function countsOf(DmRun $run): array
    {
        $sent = (int) $run->sent_count;
        $noInbox = (int) $run->no_inbox_count;
        $failed = (int) $run->failed_count;

        return [
            'sent' => $sent,
            'no_inbox' => $noInbox,
            'failed' => $failed,
            'waiting' => max(0, $run->recipient_count - $sent - $noInbox - $failed),
            'seen' => (int) $run->seen_count,
            'retry' => $failed,
            'skipped' => count($run->skipped_ids ?? []),
        ];
    }

    /**
     * @param  array{sent: int, no_inbox: int, failed: int, waiting: int, seen: int, retry: int, skipped: int}  $counts
     */
    public function countsSentence(array $counts): string
    {
        $parts = array_filter([
            $counts['sent'] > 0 ? __(':count an Relays übergeben', ['count' => $counts['sent']]) : null,
            $counts['no_inbox'] > 0 ? __(':count ohne Posteingang', ['count' => $counts['no_inbox']]) : null,
            $counts['failed'] > 0 ? __(':count fehlgeschlagen', ['count' => $counts['failed']]) : null,
            $counts['waiting'] > 0 ? __(':count warten', ['count' => $counts['waiting']]) : null,
            ($counts['skipped'] ?? 0) > 0 ? __(':count übersprungen', ['count' => $counts['skipped']]) : null,
        ]);

        return $parts === [] ? __('Keine Empfänger') : implode(', ', $parts);
    }

    /**
     * The relays' answers for one delivery, as a short sentence.
     */
    protected function relaySummary(DmDelivery $delivery): string
    {
        $relays = $delivery->relays;

        if ($relays->isEmpty()) {
            return $delivery->status === DmDeliveryStatus::NoInbox ? __('keine Relays angegeben') : '';
        }

        $accepted = $relays->where('status', RelayPublishStatus::Accepted)->count();

        return __(':accepted von :total Relays haben angenommen', ['accepted' => $accepted, 'total' => $relays->count()]);
    }

    /**
     * The state of one delivery as the board reads it: a stuck claim is
     * failed, not "being sent".
     */
    protected function deliveryFailed(DmDelivery $delivery): bool
    {
        return $delivery->status->isFailure() || $delivery->isStuck(now());
    }

    protected function deliveryLabel(DmDelivery $delivery): string
    {
        return $delivery->isStuck(now()) ? __('Hängen geblieben, gilt als fehlgeschlagen') : $delivery->status->label();
    }

    protected function nameOf(?EinundzwanzigPleb $member): string
    {
        return MemberProposal::authorNameOf($member);
    }
};
?>

{{-- Verlauf: planned rows first (they are what happens next), then every run, newest first. --}}
<div class="space-y-12">
    @if ($this->upcoming->isNotEmpty())
        <section aria-labelledby="upcoming-heading" data-test="upcoming">
            <h2 id="upcoming-heading" class="text-xl font-bold text-text-primary">{{ __('Geplant') }}</h2>
            <ul class="mt-4 divide-y divide-border-default border-y border-border-default">
                @foreach ($this->upcoming as $row)
                    <li wire:key="upcoming-{{ $row->id }}" class="grid gap-x-6 gap-y-1 py-4 md:grid-cols-[13rem_minmax(0,1fr)]">
                        <time datetime="{{ $row->send_at->toIso8601String() }}" class="text-base text-text-primary">{{ $this->longDate($row->send_at) }}</time>
                        <div class="min-w-0">
                            <p class="text-base font-bold text-text-primary">{{ $row->message->type->label() }}</p>
                            <p class="text-sm text-text-secondary break-words">
                                {{ __(':audience, Wahl „:election“', ['audience' => $row->audience->label(), 'election' => $row->election->name]) }}
                            </p>
                            @if ($row->election->is_test)
                                <p class="mt-1 flex items-center gap-2 text-sm text-warning">
                                    <flux:icon.beaker variant="micro" class="size-4 shrink-0" aria-hidden="true" />
                                    {{ __('Probewahl: der Zeitplan sendet diese Nachricht nicht.') }}
                                </p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <section aria-labelledby="runs-heading" data-test="runs">
        <h2 id="runs-heading" class="text-xl font-bold text-text-primary">{{ __('Gesendet') }}</h2>

        @if ($this->runs->isEmpty())
            <div class="mt-4 max-w-2xl rounded-lg border border-dashed border-border-default px-6 py-10" data-test="runs-empty">
                <p class="text-lg font-semibold text-text-primary">{{ __('Noch keine Nachricht gesendet.') }}</p>
                <p class="mt-2 text-base text-text-secondary">{{ __('Die erste ist meist die Ankündigung der Wahl. Schreib sie unter „Neue Nachricht“ oder plane sie im Zeitplan.') }}</p>
            </div>
        @else
            <ul class="mt-4 divide-y divide-border-default overflow-hidden rounded-lg border border-border-default bg-bg-surface">
                @foreach ($this->runs as $run)
                    @php
                        $counts = $this->countsOf($run);
                        $total = max(1, $run->recipient_count);
                        $isOpen = $openRunId === $run->id;
                    @endphp
                    <li wire:key="run-{{ $run->id }}" @class(['bg-bg-elevated' => $isOpen]) data-test="run-{{ $run->id }}">
                        <button type="button" wire:click="toggleRun({{ $run->id }})"
                                aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="run-detail-{{ $run->id }}"
                                class="grid min-h-11 w-full gap-x-6 gap-y-2 px-4 py-4 text-start hover:bg-white/[4%] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-orange-500 sm:px-6 md:grid-cols-[10rem_minmax(0,1fr)_15rem]">
                            <time datetime="{{ $run->created_at->toIso8601String() }}" class="text-sm text-text-tertiary md:pt-0.5">{{ $this->shortDate($run->created_at) }}</time>
                            <span class="min-w-0">
                                <span class="block text-base font-bold text-text-primary">{{ $run->message?->type->label() }}</span>
                                <span class="block text-sm text-text-secondary break-words">{{ $this->recipientsOf($run) }}</span>
                            </span>
                            <span class="min-w-0">
                                {{-- The strip repeats the sentence below it; the sentence carries the information. --}}
                                <span class="flex h-2 w-full overflow-hidden rounded-sm bg-border-default" aria-hidden="true">
                                    <span class="bg-success" style="width: {{ $counts['sent'] / $total * 100 }}%"></span>
                                    <span class="bg-text-tertiary" style="width: {{ $counts['no_inbox'] / $total * 100 }}%"></span>
                                    <span class="bg-danger bg-[repeating-linear-gradient(135deg,transparent_0_3px,rgb(0_0_0/35%)_3px_6px)]" style="width: {{ $counts['failed'] / $total * 100 }}%"></span>
                                </span>
                                <span @class(['mt-2 block text-sm', 'text-danger' => $counts['failed'] > 0, 'text-text-secondary' => $counts['failed'] === 0])>
                                    @if ($counts['failed'] > 0)<flux:icon.exclamation-triangle variant="micro" class="-mt-0.5 mr-1 inline size-4" aria-hidden="true" />@endif{{ $this->countsSentence($counts) }}
                                </span>
                            </span>
                        </button>

                        @if ($isOpen && $this->openRun)
                            @php
                                $detail = $this->openRun;
                                $order = [\App\Enums\DmDeliveryStatus::Failed->value => 0, \App\Enums\DmDeliveryStatus::NoSenderKey->value => 0, \App\Enums\DmDeliveryStatus::NotSent->value => 0, \App\Enums\DmDeliveryStatus::Claimed->value => 1, \App\Enums\DmDeliveryStatus::Pending->value => 2, \App\Enums\DmDeliveryStatus::NoInbox->value => 3, \App\Enums\DmDeliveryStatus::Sent->value => 4];
                                $deliveries = $detail->deliveries->sortBy(fn ($delivery) => [$this->deliveryFailed($delivery) ? 0 : $order[$delivery->status->value], $delivery->id]);
                                $notFannedOut = $detail->recipient_count - $detail->deliveries->count();
                            @endphp
                            <div id="run-detail-{{ $run->id }}" class="space-y-8 px-4 pb-8 pt-2 sm:px-6" data-test="run-detail">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <p class="text-sm text-text-secondary">
                                        {{ __('Gestartet von :name am :when.', ['name' => $this->nameOf($run->message?->author), 'when' => $this->longDate($run->created_at)]) }}
                                        @if ($counts['seen'] > 0)
                                            {{ trans_choice('Ein Mitglied hat sie im Portal geöffnet.|:count Mitglieder haben sie im Portal geöffnet.', $counts['seen'], ['count' => $counts['seen']]) }}
                                        @endif
                                    </p>
                                    @if ($counts['retry'] > 0)
                                        <flux:button wire:click="resendFailed({{ $run->id }})" variant="primary" icon="arrow-path" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="resend-failed">
                                            {{ __('Fehlgeschlagene erneut senden (:count)', ['count' => $counts['retry']]) }}
                                        </flux:button>
                                    @endif
                                </div>

                                <div>
                                    <h3 class="text-base font-bold text-text-primary">{{ __('Text, wie geschrieben') }}</h3>
                                    <p class="mt-1 text-sm text-text-tertiary">{{ __('{name} und die anderen Platzhalter wurden je Empfänger ersetzt.') }}</p>
                                    <div class="mt-3 max-w-[65ch] rounded-lg border border-border-default bg-bg-page px-4 py-3 text-base leading-7 break-words whitespace-pre-line text-text-primary">{{ $run->message?->plaintext }}</div>
                                </div>

                                <div>
                                    <h3 class="text-base font-bold text-text-primary">{{ __('Stand je Empfänger') }}</h3>
                                    @if ($notFannedOut > 0)
                                        <p class="mt-1 text-sm text-text-secondary">{{ trans_choice('Ein Empfänger wartet noch darauf, an die Reihe zu kommen.|:count Empfänger warten noch darauf, an die Reihe zu kommen.', $notFannedOut, ['count' => $notFannedOut]) }}</p>
                                    @endif
                                    @if ($deliveries->isNotEmpty())
                                        <ul class="mt-3 divide-y divide-border-subtle border-y border-border-subtle" aria-label="{{ __('Empfänger') }}">
                                            @foreach ($deliveries as $delivery)
                                                <li wire:key="delivery-{{ $delivery->id }}" class="grid gap-x-6 gap-y-1 py-3 sm:grid-cols-[minmax(0,1fr)_16rem_13rem]" data-test="delivery-{{ $delivery->id }}" data-status="{{ $delivery->status->value }}"@if ($delivery->isStuck(now())) data-stuck="true"@endif>
                                                    <span class="min-w-0 text-base text-text-primary break-words"><x-nostr-profile :pubkey="$delivery->recipient?->pubkey" :picture="$delivery->recipient?->profile?->picture" :name="$this->nameOf($delivery->recipient)" /></span>
                                                    @php
                                                        $deliveryFailed = $this->deliveryFailed($delivery);
                                                    @endphp
                                                    <span @class([
                                                        'flex items-center gap-2 text-sm',
                                                        'text-danger' => $deliveryFailed,
                                                        'text-text-primary' => $delivery->status === \App\Enums\DmDeliveryStatus::Sent,
                                                        'text-text-secondary' => ! $deliveryFailed && $delivery->status !== \App\Enums\DmDeliveryStatus::Sent,
                                                    ])>
                                                        @switch($deliveryFailed ? \App\Enums\DmDeliveryStatus::Failed : $delivery->status)
                                                            @case(\App\Enums\DmDeliveryStatus::Sent)
                                                                <flux:icon.check-circle variant="mini" class="size-5 shrink-0 text-success" aria-hidden="true" />
                                                                @break
                                                            @case(\App\Enums\DmDeliveryStatus::Failed)
                                                                <flux:icon.exclamation-triangle variant="mini" class="size-5 shrink-0" aria-hidden="true" />
                                                                @break
                                                            @case(\App\Enums\DmDeliveryStatus::NoInbox)
                                                                <flux:icon.inbox variant="mini" class="size-5 shrink-0 text-text-tertiary" aria-hidden="true" />
                                                                @break
                                                            @default
                                                                <flux:icon.clock variant="mini" class="size-5 shrink-0 text-text-tertiary" aria-hidden="true" />
                                                        @endswitch
                                                        {{ $this->deliveryLabel($delivery) }}
                                                    </span>
                                                    <span class="text-sm text-text-tertiary">
                                                        {{ $this->relaySummary($delivery) }}@if ($delivery->seen_at)<span class="block">{{ __('im Portal geöffnet') }}</span>@endif
                                                    </span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
