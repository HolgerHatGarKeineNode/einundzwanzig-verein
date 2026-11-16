<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundState;
use App\Enums\ElectionTieKind;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionRound;
use App\Models\MemberProposal;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\RoundReport;
use App\Support\ElectionTieText;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The minutes of an election for the record of the meeting: the election,
 * every round with its numbers and outcome, the check of the audit chain and
 * the manifest hash of every sealed round, and the audit lines. Printable,
 * and copyable as plain text.
 *
 * Counts only — no line names who voted, let alone how.
 */
new #[Layout('layouts.bare')] #[Title('Beschlussprotokoll')] class extends Component
{
    #[Locked]
    public int $electionId;

    public function mount(Election $election): void
    {
        $this->actor();

        $this->electionId = (int) $election->id;
    }

    #[Computed]
    public function election(): Election
    {
        $this->actor();

        return Election::query()->findOrFail($this->electionId);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function reports(): Collection
    {
        $this->actor();

        return app(RoundReport::class)->forElection($this->election);
    }

    /**
     * @return array{intact: bool, lines: int, head: string}
     */
    #[Computed]
    public function chain(): array
    {
        $this->actor();

        $audit = app(ElectionAuditLog::class);

        return [
            'intact' => $audit->verify($this->election),
            'lines' => ElectionAuditEntry::query()->where('election_event_id', $this->electionId)->count(),
            'head' => $audit->headHash($this->election),
        ];
    }

    /**
     * @return Collection<int, array{sequence: int, time: string, action: string, round: string|null, actor: string|null, subject: string|null, reason: string|null, chair: string|null}>
     */
    #[Computed]
    public function auditLines(): Collection
    {
        $this->actor();

        $entries = ElectionAuditEntry::query()->where('election_event_id', $this->electionId)->orderBy('sequence')->get();
        $people = EinundzwanzigPleb::query()
            ->whereIn('id', $entries->pluck('actor_pleb_id')->merge($entries->pluck('subject_pleb_id'))->filter()->unique())
            ->with('profile')
            ->get()
            ->keyBy('id');
        $titles = $this->reports->mapWithKeys(fn (array $report): array => [$report['round']->id => $report['round']->title]);

        return $entries->map(fn (ElectionAuditEntry $entry): array => [
            'sequence' => (int) $entry->sequence,
            'time' => $this->stamp($entry->created_at),
            'action' => $this->actionLabel($entry->action),
            'round' => $entry->election_round_id ? $titles->get($entry->election_round_id) : null,
            'actor' => $entry->actor_pleb_id ? MemberProposal::authorNameOf($people->get($entry->actor_pleb_id)) : null,
            'subject' => $entry->subject_pleb_id ? MemberProposal::authorNameOf($people->get($entry->subject_pleb_id)) : null,
            'reason' => is_array($entry->details) ? ($entry->details['reason'] ?? null) : null,
            'chair' => is_array($entry->details) ? ($entry->details['chair'] ?? null) : null,
        ]);
    }

    /**
     * The same minutes as plain text, for pasting into the record.
     */
    #[Computed]
    public function plainText(): string
    {
        $election = $this->election;
        $lines = [
            __('Beschlussprotokoll: :name', ['name' => $election->name]),
        ];

        if ($election->is_test) {
            $lines[] = __('PROBEWAHL, keine echte Wahl.');
        }

        $lines[] = '';
        $lines[] = __('Sitzung: :date', ['date' => $election->meeting_at ? $this->stamp($election->meeting_at) : __('kein Termin')]);
        $lines[] = __('Wahlberechtigt: Aktiv- und Ehrenmitglieder mit :rule', ['rule' => $this->feeRuleLabel($election)]).($election->honorary_without_fee ? __(', Ehrenmitglieder auch ohne Beitrag') : '').'.';

        foreach ($this->reports as $index => $report) {
            $round = $report['round'];
            $lines[] = '';
            $lines[] = ($index + 1).'. '.$round->title.' ('.$this->ruleText($round->counting_rule, $round->seats, $round->parent_round_id !== null).')';
            $lines[] = '   '.$this->stateText($report);

            if ($round->opened_at !== null) {
                $lines[] = '   '.__('Wahlberechtigt: :roll, abgestimmt: :cast', ['roll' => $report['roll'], 'cast' => $report['cast']]);
            }

            if ($report['result'] !== null) {
                array_push($lines, ...$this->resultLines($round->counting_rule, $report['result'], $report));
            }

            if ($report['void_result'] !== null) {
                array_push($lines, ...$this->resultLines($round->counting_rule, $report['void_result']['result'], $report, void: true));
            }

            $manifest = $report['manifest'] ?? $report['void_result']['manifest'] ?? null;

            // Annulled after its count: result and checksum first, then the annulment with its reason.
            if ($report['reason'] && $report['void_result'] === null) {
                $lines[] = '   '.__('Grund: :reason', ['reason' => $report['reason']]);
            }

            if ($manifest) {
                $lines[] = '   '.__('Prüfsumme (SHA-256): :hash', ['hash' => $manifest['hash']]).($manifest['intact'] ? '' : ' '.__('(stimmt nicht mehr mit den Stimmen überein)'));
            }

            if ($report['void_result'] !== null) {
                $lines[] = '   '.$this->voidText($round).($report['reason'] ? ' '.__('Grund: :reason', ['reason' => $report['reason']]) : '');
            }
        }

        $chain = $this->chain;
        $lines[] = '';
        $lines[] = $chain['intact']
            ? trans_choice('{1} Protokollkette: unversehrt, 1 Eintrag.|[0,*] Protokollkette: unversehrt, :count Einträge.', $chain['lines'])
            : trans_choice('{1} Protokollkette: BESCHÄDIGT, 1 Eintrag.|[0,*] Protokollkette: BESCHÄDIGT, :count Einträge.', $chain['lines']);
        $lines[] = __('Letzte Prüfsumme der Kette: :head', ['head' => $chain['head']]);
        $lines[] = __('Erstellt am :date', ['date' => $this->stamp(now())]);

        return implode("\n", $lines);
    }

    protected function stamp(CarbonInterface $moment): string
    {
        return $moment->copy()->setTimezone(config('app.display_timezone'))->format('d.m.Y, H:i').' '.__('Uhr');
    }

    protected function clock(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : $moment->copy()->setTimezone(config('app.display_timezone'))->format('H:i');
    }

    protected function feeRuleLabel(Election $election): string
    {
        $year = $election->fee_year ?? (int) now()->year;

        return match ($election->fee_rule) {
            ElectionFeeRule::Year => __('Beitrag :year', ['year' => $year]),
            ElectionFeeRule::YearOrPrevious => __('Beitrag :year oder :previous', ['year' => $year, 'previous' => $year - 1]),
            ElectionFeeRule::LastTwelveMonths => __('Beitrag in den letzten 12 Monaten'),
        };
    }

    protected function ruleText(ElectionCountingRule $rule, int $seats, bool $isRunOff): string
    {
        $label = $isRunOff ? __('Stichwahl') : match ($rule) {
            ElectionCountingRule::SingleMajority => __('Einzelwahl'),
            ElectionCountingRule::YesNoPerCandidate => __('Bestätigungswahl'),
            ElectionCountingRule::MostVotes => __('Mehrfachwahl'),
        };

        return $label.', '.trans_choice('{1} ein Sitz|[2,*] :count Sitze', $seats, ['count' => $seats]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function stateText(array $report): string
    {
        $round = $report['round'];

        return match (true) {
            $report['state'] === ElectionRoundState::Annulled && $round->opened_at === null => __('Nicht geöffnet, annulliert :annulled.', ['annulled' => $this->clock($round->annulled_at)]),
            $report['state'] === ElectionRoundState::Annulled && $round->closed_at !== null => __('Geöffnet :opened, geschlossen und ausgezählt :closed, annulliert :annulled.', ['opened' => $this->clock($round->opened_at), 'closed' => $this->clock($round->closed_at), 'annulled' => $this->clock($round->annulled_at)]),
            $report['state'] === ElectionRoundState::Annulled => __('Geöffnet :opened, annulliert :annulled.', ['opened' => $this->clock($round->opened_at), 'annulled' => $this->clock($round->annulled_at)]),
            $report['sealed'] => __('Geöffnet :opened, geschlossen und ausgezählt :closed.', ['opened' => $this->clock($round->opened_at), 'closed' => $this->clock($round->closed_at)]),
            $report['state'] === ElectionRoundState::Open => __('Geöffnet :opened, läuft noch.', ['opened' => $this->clock($round->opened_at)]),
            $report['over_by_clock'] => __('Geöffnet :opened, Zeit abgelaufen, noch nicht ausgezählt.', ['opened' => $this->clock($round->opened_at)]),
            default => __('Nicht geöffnet.'),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function countText(ElectionCountingRule $rule, array $row): string
    {
        return $rule === ElectionCountingRule::YesNoPerCandidate
            ? __(':yes Ja, :no Nein', ['yes' => $row['yes'], 'no' => $row['no']])
            : trans_choice('{0} 0 Stimmen|{1} 1 Stimme|[2,*] :count Stimmen', $row['for'], ['count' => $row['for']]);
    }

    /**
     * The rows, abstentions and outcome of a count, as plain-text lines.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    protected function resultLines(ElectionCountingRule $rule, array $result, array $report, bool $void = false): array
    {
        $lines = [];

        foreach ($result['rows'] as $row) {
            $lines[] = '   - '.$row['name'].': '.$this->countText($rule, $row).(($verdict = $this->verdictOf($row)) === '' ? '' : ', '.$verdict);
        }

        $lines[] = '   '.__('Enthaltungen (leer): :blank', ['blank' => $result['blank']]);
        $lines[] = '   '.($void ? __('Ausgezähltes Ergebnis (ungültig): :outcome', ['outcome' => $this->outcomeText($result)]) : __('Ergebnis: :outcome', ['outcome' => $this->outcomeText($result)]));

        if (! $void && ($tieLine = $this->tieLine($result, $report)) !== null) {
            $lines[] = '   '.$tieLine;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function verdictOf(array $row): string
    {
        return match (true) {
            $row['elected'] && $row['by_casting_vote'] => __('gewählt durch Stichentscheid'),
            $row['elected'] => __('gewählt'),
            $row['tied'] => __('Gleichstand'),
            default => '',
        };
    }

    /**
     * What became of a tie: the chair's casting vote, a run-off the assembly
     * resolved, or the casting vote still to come. Null without a tie.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $report
     */
    protected function tieLine(array $result, array $report): ?string
    {
        $castingVote = $result['casting_vote'];

        if ($castingVote !== null) {
            return ElectionTieText::decided($result['tie_kind'], $castingVote['chair'], $castingVote['elected'], $castingVote['tied'])
                .($castingVote['note'] ? ' '.__('Anmerkung: :note', ['note' => $castingVote['note']]) : '')
                .' '.__('Eingetragen :time.', ['time' => $this->stamp($castingVote['recorded_at'])]);
        }

        if ($result['outcome'] !== ElectionOutcome::Tie) {
            return null;
        }

        return $report['run_off_resolved'] ? ElectionTieText::runOffResolved() : ElectionTieText::pending();
    }

    /**
     * The mark of a round annulled after its count: the result above stands
     * in the record, and it is not valid.
     */
    protected function voidText(ElectionRound $round): string
    {
        return __('ANNULLIERT :time. Das ausgezählte Ergebnis ist nicht gültig.', ['time' => $this->stamp($round->annulled_at)]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    protected function outcomeText(array $result): string
    {
        $elected = collect($result['rows'])->where('elected', true)->pluck('name');
        $tied = collect($result['rows'])->where('tied', true)->pluck('name');

        return match ($result['outcome']) {
            ElectionOutcome::Complete => trans_choice('{1} gewählt ist :names|[2,*] gewählt sind :names', $elected->count(), ['names' => $elected->join(', ', ' und ')]),
            ElectionOutcome::Tie => ($result['tie_kind'] === ElectionTieKind::YesEqualsNo
                ? trans_choice('{1} Gleichstand von Ja und Nein bei :names|[2,*] Gleichstand von Ja und Nein bei :names', $tied->count(), ['names' => $tied->join(', ', ' und ')])
                : trans_choice('{1} Gleichstand um einen Sitz|[2,*] Gleichstand um :count Sitze', $result['tied_seats'], ['count' => $result['tied_seats']]))
                .($elected->isNotEmpty() ? '; '.__('gewählt: :names', ['names' => $elected->join(', ', ' und ')]) : ''),
            ElectionOutcome::Understaffed => $elected->isEmpty()
                ? __('niemand hat die nötige Mehrheit')
                : __('nicht alle Sitze besetzt; gewählt: :names', ['names' => $elected->join(', ', ' und ')]),
        };
    }

    protected function actionLabel(ElectionAuditAction $action): string
    {
        return match ($action) {
            ElectionAuditAction::ElectionCreated => __('Wahl angelegt'),
            ElectionAuditAction::ElectionUpdated => __('Wahl geändert'),
            ElectionAuditAction::ElectionAnnounced => __('Wahl angekündigt'),
            ElectionAuditAction::ElectionDeleted => __('Wahl gelöscht'),
            ElectionAuditAction::CandidacyApproved => __('Kandidatur freigegeben'),
            ElectionAuditAction::CandidacyRejected => __('Kandidatur abgelehnt'),
            ElectionAuditAction::CandidacyWithdrawn => __('Kandidatur zurückgezogen'),
            ElectionAuditAction::RoundOpened => __('Wahlgang geöffnet'),
            ElectionAuditAction::RoundClosed => __('Wahlgang ausgezählt'),
            ElectionAuditAction::RoundClosedEarly => __('Wahlgang geschlossen und ausgezählt'),
            ElectionAuditAction::RoundAnnulled => __('Wahlgang annulliert'),
            ElectionAuditAction::VoterEnrolledByHand => __('Von Hand ins Verzeichnis nachgetragen'),
            ElectionAuditAction::ElectionMeetingUpdated => __('Termin oder Call-Link geändert'),
            ElectionAuditAction::RunOffCreated => __('Stichwahl angelegt'),
            ElectionAuditAction::CastingVoteRecorded => __('Stichentscheid eingetragen'),
            ElectionAuditAction::RoundRepeated => __('Wahlgang zur Wiederholung angelegt'),
        };
    }

    protected function actor(): EinundzwanzigPleb
    {
        $pleb = NostrAuth::user()?->getPleb();

        abort_unless($pleb instanceof EinundzwanzigPleb && ! $pleb->isErased() && $pleb->isBoardMember(), 403);

        return $pleb;
    }
};
?>

@php
    $election = $this->election;
    $chain = $this->chain;
@endphp

<div class="election-ui mx-auto max-w-3xl px-4 py-8 sm:px-8 sm:py-12 print:max-w-none print:p-0" data-test="protocol">
    <nav class="mb-8 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center print:hidden" aria-label="{{ __('Aktionen') }}"
         x-data="{ copied: false, copy() { navigator.clipboard.writeText($refs.plain.value).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2500) }, () => { $refs.plain.closest('details').open = true; $refs.plain.select() }) } }">
        <flux:button :href="route('association.election.admin')" icon="arrow-left" variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Zur Wahlleitung') }}</flux:button>
        <span class="hidden flex-1 sm:block"></span>
        <flux:button x-on:click="window.print()" icon="printer" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="print-protocol">{{ __('Drucken') }}</flux:button>
        <flux:button x-on:click="copy()" icon="clipboard-document" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="copy-protocol">
            <span x-show="! copied">{{ __('Als Text kopieren') }}</span>
            <span x-show="copied" x-cloak>{{ __('Kopiert') }}</span>
        </flux:button>
        <details class="w-full">
            <summary class="min-h-11 cursor-pointer py-3 text-base text-text-secondary">{{ __('Text ansehen') }}</summary>
            <textarea x-ref="plain" readonly rows="14" class="mt-2 w-full rounded-lg border border-border-default bg-bg-surface p-3 text-sm text-text-primary" data-test="protocol-text">{{ $this->plainText }}</textarea>
        </details>
    </nav>

    <header class="border-b border-border-default pb-6 print:border-black">
        <p class="text-base text-text-secondary print:text-black">{{ __('EINUNDZWANZIG e. V., Beschlussprotokoll') }}</p>
        <h1 class="mt-1 text-3xl font-bold leading-tight break-words">{{ $election->name }}</h1>
        @if ($election->is_test)
            <p class="mt-3 inline-flex items-center gap-2 text-lg font-bold text-warning print:text-black" data-test="protocol-test-marker">
                <flux:icon.beaker variant="mini" class="size-5" aria-hidden="true" />{{ __('Probewahl, keine echte Wahl') }}
            </p>
        @endif
        <dl class="mt-6 grid gap-x-6 gap-y-2 text-base sm:grid-cols-[12rem_minmax(0,1fr)]">
            <dt class="text-text-secondary print:text-black">{{ __('Sitzung') }}</dt>
            <dd>{{ $election->meeting_at ? $this->stamp($election->meeting_at) : __('kein Termin') }}</dd>
            <dt class="text-text-secondary print:text-black">{{ __('Wahlberechtigt') }}</dt>
            <dd>{{ __('Aktiv- und Ehrenmitglieder mit :rule', ['rule' => $this->feeRuleLabel($election)]) }}{{ $election->honorary_without_fee ? __(', Ehrenmitglieder auch ohne Beitrag') : '' }}</dd>
            @if ($election->announced_at)
                <dt class="text-text-secondary print:text-black">{{ __('Angekündigt') }}</dt>
                <dd>{{ $this->stamp($election->announced_at) }}</dd>
            @endif
        </dl>
    </header>

    <section class="mt-10" aria-labelledby="protocol-rounds">
        <h2 id="protocol-rounds" class="text-2xl font-bold">{{ __('Wahlgänge') }}</h2>
        <ol class="mt-4 space-y-8">
            @foreach ($this->reports as $index => $report)
                @php
                    $round = $report['round'];
                    $result = $report['result'];
                @endphp
                <li class="break-inside-avoid border-t border-border-subtle pt-6 print:border-black" data-test="protocol-round-{{ $round->id }}">
                    <h3 class="text-xl font-bold break-words">{{ $index + 1 }}. {{ $round->title }}</h3>
                    <p class="mt-1 text-base text-text-secondary print:text-black">{{ $this->ruleText($round->counting_rule, $round->seats, $round->parent_round_id !== null) }}. {{ $this->stateText($report) }}</p>

                    @if ($round->opened_at !== null)
                        <p class="mt-3 text-base">{{ __('Wahlberechtigt: :roll, abgestimmt: :cast', ['roll' => $report['roll'], 'cast' => $report['cast']]) }}</p>
                    @endif

                    @foreach (array_filter(['result' => $result, 'void' => $report['void_result']['result'] ?? null]) as $kind => $shown)
                        <table @class(['mt-4 w-full text-left text-base', 'opacity-75 print:opacity-100' => $kind === 'void']) @if ($kind === 'void') data-test="protocol-void-result-{{ $round->id }}" @endif>
                            <thead>
                                <tr class="border-b border-border-default text-sm text-text-secondary print:border-black print:text-black">
                                    <th scope="col" class="py-2 pr-4 font-normal">{{ __('Kandidatur') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-normal">{{ __('Stimmen') }}</th>
                                    <th scope="col" class="hidden py-2 font-normal sm:table-cell print:table-cell"><span class="sr-only">{{ __('Ergebnis') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($shown['rows'] as $row)
                                    <tr class="border-b border-border-subtle print:border-neutral-400">
                                        @php $verdict = $this->verdictOf($row); @endphp
                                        <td class="py-2 pr-4 align-top font-bold [overflow-wrap:anywhere]">
                                            {{ $row['name'] }}
                                            @if ($verdict !== '')
                                                <span class="block font-normal sm:hidden print:hidden">{{ $verdict }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 align-top text-right tabular-nums whitespace-nowrap">{{ $this->countText($round->counting_rule, $row) }}</td>
                                        <td class="hidden py-2 align-top sm:table-cell print:table-cell">{{ $verdict }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-3 text-base">{{ __('Enthaltungen (leer): :blank', ['blank' => $shown['blank']]) }}</p>
                        @if ($kind === 'void')
                            <p class="mt-1 text-base font-bold break-words" data-test="protocol-outcome-{{ $round->id }}">{{ __('Ausgezähltes Ergebnis (ungültig): :outcome', ['outcome' => $this->outcomeText($shown)]) }}</p>
                        @else
                            <p class="mt-1 text-base font-bold break-words" data-test="protocol-outcome-{{ $round->id }}">{{ __('Ergebnis: :outcome', ['outcome' => $this->outcomeText($shown)]) }}</p>
                            @if (($tieLine = $this->tieLine($shown, $report)) !== null)
                                <p class="mt-1 text-base break-words" data-test="protocol-tie-{{ $round->id }}">{{ $tieLine }}</p>
                            @endif
                        @endif
                    @endforeach

                    @if ($report['reason'] && $report['void_result'] === null)
                        <p class="mt-2 text-base break-words">{{ __('Grund: :reason', ['reason' => $report['reason']]) }}</p>
                    @endif

                    @php $manifest = $report['manifest'] ?? $report['void_result']['manifest'] ?? null; @endphp
                    @if ($manifest)
                        <p class="mt-2 text-sm text-text-secondary break-all print:text-black" data-test="protocol-manifest-{{ $round->id }}">
                            {{ __('Prüfsumme (SHA-256): :hash', ['hash' => $manifest['hash']]) }}
                            @if ($report['void_result'] !== null)
                                {{ $manifest['intact'] ? __('Der gespeicherte Text hat diese Prüfsumme noch.') : __('Der gespeicherte Text hat diese Prüfsumme NICHT mehr.') }}
                            @else
                                {{ $manifest['intact'] ? __('stimmt mit den gespeicherten Stimmen überein.') : __('stimmt NICHT mehr mit den gespeicherten Stimmen überein.') }}
                            @endif
                        </p>
                    @endif

                    @if ($report['void_result'] !== null)
                        <p class="mt-2 inline-flex items-start gap-2 text-base font-bold text-danger break-words print:text-black" data-test="protocol-void-{{ $round->id }}">
                            <flux:icon.no-symbol variant="mini" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <span>{{ $this->voidText($round) }}@if ($report['reason']) {{ __('Grund: :reason', ['reason' => $report['reason']]) }}@endif</span>
                        </p>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>

    <section class="mt-10 break-inside-avoid" aria-labelledby="protocol-check">
        <h2 id="protocol-check" class="text-2xl font-bold">{{ __('Prüfung') }}</h2>
        <p @class(['mt-4 inline-flex items-start gap-2 text-lg font-bold', 'text-success print:text-black' => $chain['intact'], 'text-danger print:text-black' => ! $chain['intact']]) data-test="protocol-chain">
            @if ($chain['intact'])
                <flux:icon.check-circle variant="mini" class="mt-1 size-5 shrink-0" aria-hidden="true" />{{ trans_choice('{1} Protokollkette unversehrt, 1 Eintrag.|[0,*] Protokollkette unversehrt, :count Einträge.', $chain['lines']) }}
            @else
                <flux:icon.exclamation-triangle variant="mini" class="mt-1 size-5 shrink-0" aria-hidden="true" />{{ trans_choice('{1} Protokollkette beschädigt, 1 Eintrag.|[0,*] Protokollkette beschädigt, :count Einträge.', $chain['lines']) }}
            @endif
        </p>
        <p class="mt-2 text-sm text-text-secondary break-all print:text-black">{{ __('Letzte Prüfsumme der Kette: :head', ['head' => $chain['head']]) }}</p>
        <p class="mt-2 text-sm text-text-secondary print:text-black">{{ __('Die vollständige Prüfung samt Signatur jeder Stimme macht der Befehl „election:verify“ je Wahlgang.') }}</p>
    </section>

    <section class="mt-10" aria-labelledby="protocol-log">
        <h2 id="protocol-log" class="text-2xl font-bold">{{ __('Verlauf') }}</h2>
        <ol class="mt-4 divide-y divide-border-subtle border-y border-border-subtle text-base print:divide-neutral-400 print:border-neutral-400">
            @foreach ($this->auditLines as $line)
                <li class="grid gap-x-4 py-2 sm:grid-cols-[11rem_minmax(0,1fr)]">
                    <span class="text-text-secondary tabular-nums print:text-black">{{ $line['time'] }}</span>
                    <span class="min-w-0 break-words">
                        <span class="font-bold">{{ $line['action'] }}</span>@if ($line['round']), {{ $line['round'] }}@endif @if ($line['subject'])({{ $line['subject'] }})@endif
                        @if ($line['actor'])<span class="text-text-secondary print:text-black">{{ __('durch :name', ['name' => $line['actor']]) }}</span>@endif
                        @if ($line['chair'])<span class="block text-sm text-text-secondary print:text-black">{{ __('Vorsitz: :chair', ['chair' => $line['chair']]) }}</span>@endif
                        @if ($line['reason'])<span class="block text-sm text-text-secondary print:text-black">{{ __('Grund: :reason', ['reason' => $line['reason']]) }}</span>@endif
                    </span>
                </li>
            @endforeach
        </ol>
    </section>

    <p class="mt-10 text-sm text-text-tertiary print:text-black">{{ __('Erstellt am :date', ['date' => $this->stamp(now())]) }}</p>
</div>
