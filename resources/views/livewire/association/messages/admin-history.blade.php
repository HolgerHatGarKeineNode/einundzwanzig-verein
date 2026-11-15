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
            <div class="mt-4 max-w-2xl rounded-xl border border-dashed border-border-default px-5 py-8" data-test="runs-empty">
                <p class="text-lg font-semibold text-text-primary">{{ __('Noch keine Nachricht gesendet.') }}</p>
                <p class="mt-2 text-base text-text-secondary">{{ __('Die erste ist meist die Ankündigung der Wahl. Schreib sie unter „Neue Nachricht“ oder plane sie im Zeitplan.') }}</p>
            </div>
        @else
            <ul class="mt-4 divide-y divide-border-default overflow-hidden rounded-xl border border-border-default bg-bg-surface">
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
                                $order = [\App\Enums\DmDeliveryStatus::Failed->value => 0, \App\Enums\DmDeliveryStatus::Claimed->value => 1, \App\Enums\DmDeliveryStatus::Pending->value => 2, \App\Enums\DmDeliveryStatus::NoInbox->value => 3, \App\Enums\DmDeliveryStatus::Sent->value => 4];
                                $deliveries = $detail->deliveries->sortBy(fn ($delivery) => [$order[$delivery->status->value], $delivery->id]);
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
                                                <li wire:key="delivery-{{ $delivery->id }}" class="grid gap-x-6 gap-y-1 py-3 sm:grid-cols-[minmax(0,1fr)_16rem_13rem]" data-test="delivery-{{ $delivery->id }}" data-status="{{ $delivery->status->value }}">
                                                    <span class="min-w-0 text-base text-text-primary break-words">{{ $this->nameOf($delivery->recipient) }}</span>
                                                    <span @class([
                                                        'flex items-center gap-2 text-sm',
                                                        'text-danger' => $delivery->status === \App\Enums\DmDeliveryStatus::Failed,
                                                        'text-text-primary' => $delivery->status === \App\Enums\DmDeliveryStatus::Sent,
                                                        'text-text-secondary' => ! in_array($delivery->status, [\App\Enums\DmDeliveryStatus::Failed, \App\Enums\DmDeliveryStatus::Sent], true),
                                                    ])>
                                                        @switch($delivery->status)
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
                                                        {{ $delivery->status->label() }}
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
