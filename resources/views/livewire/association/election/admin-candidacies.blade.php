<?php

use App\Enums\ElectionCandidateStatus;
use App\Exceptions\ElectionActionRefusedException;
use App\Livewire\Traits\WithViewer;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use App\Services\Election\Candidacies;
use App\Services\Election\ElectoralRoll;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * The candidacies of an election on the board's election page, per round:
 * approve, reject or withdraw, the last two with a reason for the minutes.
 *
 * A child of `association.election.admin`, and callable on its own like
 * every Livewire component: each public method asks the board guard itself,
 * and a candidacy of another election is not found. After a decision it
 * tells the page `election-changed`, so the run sheet and the tab follow.
 */
new class extends Component
{
    use WithViewer;

    #[Locked]
    public int $electionId;

    #[Locked]
    public ?int $decisionCandidateId = null;

    #[Locked]
    public ?string $decisionKind = null;

    public string $decisionReason = '';

    /**
     * Whether the tab is open. The page keeps this tab mounted while another
     * one is open, so a reason being written survives a look at another tab, as it did when
     * the state lived on the page; hidden, it renders nothing.
     */
    #[Reactive]
    public bool $visible = true;

    public function mount(int $electionId): void
    {
        $this->boardActor();

        $this->electionId = $electionId;
    }

    public function approve(int $candidateId, Candidacies $candidacies): void
    {
        $actor = $this->boardActor();
        $candidate = $this->candidate($candidateId);

        try {
            $candidacies->approve($candidate, $actor);
        } catch (AuthorizationException) {
            Flux::toast(__('Über die Kandidatur von :name ist schon entschieden. Lade die Seite neu, vielleicht hat sie schon jemand bearbeitet.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]), variant: 'danger');
            $this->decided();

            return;
        } catch (ElectionActionRefusedException) {
            Flux::toast(
                __(':name ist nach der Beitragsregel dieser Wahl noch nicht wahlberechtigt. Freigeben geht nach der Zahlung. Hat :name bar bezahlt, tragt die Wahlberechtigung unter „Wählerverzeichnis“ von Hand ein.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]),
                variant: 'danger',
            );

            return;
        }

        $this->decided();
        Flux::toast(__(':name ist freigegeben.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]), variant: 'success');
    }

    public function startDecision(int $candidateId, string $kind): void
    {
        $this->boardActor();

        $this->decisionCandidateId = (int) $this->candidate($candidateId)->id;
        $this->decisionKind = $kind === 'withdraw' ? 'withdraw' : 'reject';
        $this->decisionReason = '';
        $this->resetErrorBag('decisionReason');

        Flux::modal('candidacy-decision')->show();
    }

    public function confirmDecision(Candidacies $candidacies): void
    {
        $actor = $this->boardActor();

        $this->validate(
            ['decisionReason' => ['required', 'string', 'min:3', 'max:1000']],
            [
                'decisionReason.required' => __('Nenne einen Grund. Er steht im Protokoll der Wahl.'),
                'decisionReason.min' => __('Nenne einen Grund mit mindestens drei Zeichen.'),
                'decisionReason.max' => __('Der Grund darf höchstens 1000 Zeichen lang sein.'),
            ],
        );

        $candidate = $this->candidate($this->decisionCandidateId ?? abort(404));

        try {
            if ($this->decisionKind === 'withdraw') {
                $candidacies->withdraw($candidate, $actor, trim($this->decisionReason));
                $message = __('Die Kandidatur von :name ist zurückgezogen.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]);
            } else {
                $candidacies->reject($candidate, $actor, trim($this->decisionReason));
                $message = __('Die Kandidatur von :name ist abgelehnt.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]);
            }
        } catch (AuthorizationException) {
            $this->addError('decisionReason', __('Über die Kandidatur von :name ist schon entschieden. Lade die Seite neu, vielleicht hat sie schon jemand bearbeitet.', ['name' => MemberProposal::authorNameOf($candidate->pleb)]));

            return;
        }

        Flux::modal('candidacy-decision')->close();
        $this->decisionCandidateId = null;
        $this->decisionKind = null;
        $this->decisionReason = '';
        $this->decided();
        Flux::toast($message);
    }

    #[Computed]
    public function election(): Election
    {
        $this->boardActor();

        return Election::query()->findOrFail($this->electionId);
    }

    /**
     * The candidacies of the election, per first-level round, undecided
     * first.
     *
     * @return Collection<int, array{round: ElectionRound, candidates: Collection<int, array{candidate: ElectionCandidate, name: string, entitled: bool}>}>
     */
    #[Computed]
    public function candidaciesByRound(): Collection
    {
        $this->boardActor();

        $election = $this->election;
        $entitled = app(ElectoralRoll::class)->entitled($election, now())->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $order = [ElectionCandidateStatus::Pending->value => 0, ElectionCandidateStatus::Approved->value => 1, ElectionCandidateStatus::Rejected->value => 2, ElectionCandidateStatus::Withdrawn->value => 3];

        return ElectionRound::query()
            ->where('election_event_id', $election->id)
            ->whereNull('parent_round_id')
            ->orderBy('position')
            ->with('candidates.pleb.profile')
            ->get()
            ->map(fn (ElectionRound $round): array => [
                'round' => $round,
                'candidates' => $round->candidates
                    ->sortBy(fn (ElectionCandidate $candidate): string => $order[$candidate->status->value].mb_strtolower(MemberProposal::authorNameOf($candidate->pleb)))
                    ->values()
                    ->map(fn (ElectionCandidate $candidate): array => [
                        'candidate' => $candidate,
                        'name' => MemberProposal::authorNameOf($candidate->pleb),
                        'entitled' => in_array((int) $candidate->einundzwanzig_pleb_id, $entitled, true),
                    ]),
            ]);
    }

    /**
     * A candidacy of this election, or 404.
     */
    protected function candidate(int $candidateId): ElectionCandidate
    {
        return ElectionCandidate::query()
            ->whereHas('round', fn ($round) => $round->where('election_event_id', $this->electionId))
            ->with(['pleb.profile', 'round'])
            ->findOrFail($candidateId);
    }

    protected function decided(): void
    {
        unset($this->candidaciesByRound);
        $this->dispatch('election-changed');
    }
};
?>

<div>
@if ($visible)
@php
    $election = $this->election;
    $statusWords = [
        \App\Enums\ElectionCandidateStatus::Pending->value => [__('wartet auf Prüfung'), 'clock', 'text-warning'],
        \App\Enums\ElectionCandidateStatus::Approved->value => [__('freigegeben'), 'check-circle', 'text-success'],
        \App\Enums\ElectionCandidateStatus::Rejected->value => [__('abgelehnt'), 'x-circle', 'text-text-tertiary'],
        \App\Enums\ElectionCandidateStatus::Withdrawn->value => [__('zurückgezogen'), 'arrow-uturn-left', 'text-text-tertiary'],
    ];
@endphp

<div class="relative space-y-10" data-test="candidacies">
    @if ($election->announced_at === null)
        <p class="max-w-2xl text-base text-text-secondary">{{ __('Mitglieder kandidieren auf der Wahlseite, sobald die Wahl angekündigt ist. Bis dahin bleibt diese Liste leer.') }}</p>
    @else
        <p class="max-w-2xl text-base text-text-secondary">{{ __('Freigegebene Kandidaturen stehen in der Sitzung auf dem Stimmzettel. Ablehnen und Zurückziehen brauchen einen Grund; er steht im Protokoll der Wahl.') }}</p>
    @endif

    @foreach ($this->candidaciesByRound as $group)
        @php
            $round = $group['round'];
            $isDraft = $round->stateAt(now()) === \App\Enums\ElectionRoundState::Draft;
        @endphp
        <section wire:key="candidacies-round-{{ $round->id }}" aria-labelledby="candidacies-round-{{ $round->id }}" data-test="candidacies-round">
            <h3 id="candidacies-round-{{ $round->id }}" class="text-lg font-semibold text-text-primary break-words">
                {{ $loop->iteration }}. {{ $round->title }}
            </h3>
            <p class="mt-1 text-sm text-text-tertiary">
                {{ trans_choice('{1} Ein Sitz|[2,*] :count Sitze', $round->seats, ['count' => $round->seats]) }}, {{ $round->counting_rule->label() }}
            </p>

            @unless ($isDraft)
                <p class="mt-3 text-sm text-text-secondary">{{ __('Dieser Wahlgang ist geöffnet. Die Kandidaturen sind fest.') }}</p>
            @endunless

            @if ($group['candidates']->isEmpty())
                <p class="mt-4 rounded-lg border border-dashed border-border-default px-6 py-10 text-base text-text-secondary">{{ __('Noch keine Kandidatur.') }}</p>
            @else
                <ul class="mt-4 divide-y divide-border-default rounded-lg border border-border-default bg-bg-surface">
                    @foreach ($group['candidates'] as $row)
                        @php
                            $candidate = $row['candidate'];
                            [$word, $icon, $tone] = $statusWords[$candidate->status->value];
                            $pending = $candidate->status === \App\Enums\ElectionCandidateStatus::Pending;
                        @endphp
                        <li wire:key="candidate-{{ $candidate->id }}" class="relative grid gap-4 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start sm:p-5" data-test="candidate-{{ $candidate->id }}">
                            <div class="min-w-0">
                                <p class="text-base font-bold text-text-primary break-words"><x-nostr-profile :pubkey="$candidate->pleb?->pubkey" :name="$row['name']" :picture="$candidate->pleb?->profile?->picture" /></p>
                                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                    <span class="inline-flex items-center gap-1.5 {{ $tone }}" data-test="candidate-status">
                                        <flux:icon :name="$icon" variant="micro" class="size-4" aria-hidden="true" />
                                        {{ $word }}
                                    </span>
                                    @if ($pending && ! $row['entitled'])
                                        <span class="inline-flex items-center gap-1.5 text-warning" data-test="candidate-fee-missing">
                                            <flux:icon.exclamation-triangle variant="micro" class="size-4" aria-hidden="true" />
                                            {{ __(':missing, Freigabe erst nach der Zahlung', ['missing' => $election->fee_rule->missingText($election->fee_year)]) }}
                                        </span>
                                    @endif
                                </div>
                                @if (filled($candidate->statement))
                                    <details class="group mt-2">
                                        <summary class="inline-flex min-h-11 cursor-pointer items-center text-sm text-text-secondary underline underline-offset-4 hover:text-text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                            {{ __('Vorstellung lesen') }}
                                        </summary>
                                        <div class="mt-2 max-w-prose whitespace-pre-line break-words text-base text-text-primary">{{ $candidate->statement }}</div>
                                    </details>
                                @endif
                            </div>

                            @if ($isDraft && in_array($candidate->status, [\App\Enums\ElectionCandidateStatus::Pending, \App\Enums\ElectionCandidateStatus::Approved], true))
                                <div class="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                                    @if ($pending)
                                        <flux:button wire:click="approve({{ $candidate->id }})" icon="check" class="min-h-11" :disabled="! $row['entitled']" data-test="approve-{{ $candidate->id }}">{{ __('Freigeben') }}</flux:button>
                                        <flux:button wire:click="startDecision({{ $candidate->id }}, 'reject')" variant="ghost" class="min-h-11" data-test="reject-{{ $candidate->id }}">{{ __('Ablehnen') }}</flux:button>
                                    @else
                                        <flux:button wire:click="startDecision({{ $candidate->id }}, 'withdraw')" variant="ghost" class="col-span-2 min-h-11" data-test="withdraw-{{ $candidate->id }}">{{ __('Zurückziehen') }}</flux:button>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach

    @php
        $decisionCandidate = $decisionCandidateId ? \App\Models\ElectionCandidate::query()->with(['pleb.profile', 'round'])->find($decisionCandidateId) : null;
    @endphp
    <flux:modal name="candidacy-decision" class="w-full max-w-lg">
        <form wire:submit="confirmDecision" class="space-y-6">
            <div>
                <flux:heading size="lg">
                    @if ($decisionCandidate)
                        {{ $decisionKind === 'withdraw'
                            ? __('Kandidatur von :name zurückziehen?', ['name' => MemberProposal::authorNameOf($decisionCandidate->pleb)])
                            : __('Kandidatur von :name ablehnen?', ['name' => MemberProposal::authorNameOf($decisionCandidate->pleb)]) }}
                    @endif
                </flux:heading>
                <flux:text class="mt-2">
                    @if ($decisionCandidate)
                        {{ __('Wahlgang „:round“. Der Grund steht im Protokoll der Wahl.', ['round' => $decisionCandidate->round?->title]) }}
                    @endif
                </flux:text>
            </div>
            <flux:field>
                <flux:label>{{ __('Grund') }}</flux:label>
                <flux:textarea wire:model="decisionReason" rows="3" maxlength="1000" data-test="decision-reason"
                               placeholder="{{ $decisionKind === 'withdraw' ? __('z. B. auf eigenen Wunsch, per Nachricht vom 3. März') : __('z. B. Kandidatur für ein Amt, das nicht zur Wahl steht') }}" />
                <flux:error name="decisionReason" />
            </flux:field>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Abbrechen') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger" class="min-h-11 w-full sm:w-auto" data-test="confirm-decision">
                    {{ $decisionKind === 'withdraw' ? __('Zurückziehen') : __('Ablehnen') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
@endif
</div>
