<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundState;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\MemberProposal;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\RoundReport;
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
     * @return Collection<int, array{sequence: int, time: string, action: string, round: string|null, actor: string|null, subject: string|null, reason: string|null}>
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
                foreach ($report['result']['rows'] as $row) {
                    $lines[] = '   - '.$row['name'].': '.$this->countText($round->counting_rule, $row).($row['elected'] ? ', '.__('gewählt') : ($row['tied'] ? ', '.__('Gleichstand') : ''));
                }

                $lines[] = '   '.__('Enthaltungen: :blank', ['blank' => $report['result']['blank']]);
                $lines[] = '   '.__('Ergebnis: :outcome', ['outcome' => $this->outcomeText($report['result'])]);
            }

            if ($report['reason']) {
                $lines[] = '   '.__('Grund: :reason', ['reason' => $report['reason']]);
            }

            if ($report['manifest']) {
                $lines[] = '   '.__('Prüfsumme (SHA-256): :hash', ['hash' => $report['manifest']['hash']]).($report['manifest']['intact'] ? '' : ' '.__('(stimmt nicht mehr mit den Stimmen überein)'));
            }
        }

        $chain = $this->chain;
        $lines[] = '';
        $lines[] = $chain['intact']
            ? __('Protokollkette: unversehrt, :lines Einträge.', ['lines' => $chain['lines']])
            : __('Protokollkette: BESCHÄDIGT, :lines Einträge.', ['lines' => $chain['lines']]);
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
     * @param  array<string, mixed>  $result
     */
    protected function outcomeText(array $result): string
    {
        $elected = collect($result['rows'])->where('elected', true)->pluck('name');

        return match ($result['outcome']) {
            ElectionOutcome::Complete => trans_choice('{1} gewählt ist :names|[2,*] gewählt sind :names', $elected->count(), ['names' => $elected->join(', ', ' und ')]),
            ElectionOutcome::Tie => trans_choice('{1} Gleichstand um einen Sitz|[2,*] Gleichstand um :count Sitze', $result['tied_seats'], ['count' => $result['tied_seats']])
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

<div class="mx-auto max-w-3xl px-4 py-8 sm:px-8 sm:py-12 print:max-w-none print:p-0" data-test="protocol">
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

                    @if ($result !== null)
                        <table class="mt-4 w-full text-left text-base">
                            <thead>
                                <tr class="border-b border-border-default text-sm text-text-secondary print:border-black print:text-black">
                                    <th scope="col" class="py-2 pr-4 font-normal">{{ __('Kandidatur') }}</th>
                                    <th scope="col" class="py-2 pr-4 text-right font-normal">{{ __('Stimmen') }}</th>
                                    <th scope="col" class="py-2 font-normal"><span class="sr-only">{{ __('Ergebnis') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($result['rows'] as $row)
                                    <tr class="border-b border-border-subtle print:border-neutral-400">
                                        <td class="py-2 pr-4 font-bold break-words">{{ $row['name'] }}</td>
                                        <td class="py-2 pr-4 text-right tabular-nums whitespace-nowrap">{{ $this->countText($round->counting_rule, $row) }}</td>
                                        <td class="py-2">{{ $row['elected'] ? __('gewählt') : ($row['tied'] ? __('Gleichstand') : '') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="mt-3 text-base">{{ __('Enthaltungen: :blank', ['blank' => $result['blank']]) }}</p>
                        <p class="mt-1 text-base font-bold" data-test="protocol-outcome-{{ $round->id }}">{{ __('Ergebnis: :outcome', ['outcome' => $this->outcomeText($result)]) }}</p>
                    @endif

                    @if ($report['reason'])
                        <p class="mt-2 text-base">{{ __('Grund: :reason', ['reason' => $report['reason']]) }}</p>
                    @endif

                    @if ($report['manifest'])
                        <p class="mt-2 text-sm text-text-secondary break-all print:text-black" data-test="protocol-manifest-{{ $round->id }}">
                            {{ __('Prüfsumme (SHA-256): :hash', ['hash' => $report['manifest']['hash']]) }}
                            {{ $report['manifest']['intact'] ? __('stimmt mit den gespeicherten Stimmen überein.') : __('stimmt NICHT mehr mit den gespeicherten Stimmen überein.') }}
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
                <flux:icon.check-circle variant="mini" class="mt-1 size-5 shrink-0" aria-hidden="true" />{{ __('Protokollkette unversehrt, :lines Einträge.', ['lines' => $chain['lines']]) }}
            @else
                <flux:icon.exclamation-triangle variant="mini" class="mt-1 size-5 shrink-0" aria-hidden="true" />{{ __('Protokollkette beschädigt, :lines Einträge.', ['lines' => $chain['lines']]) }}
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
                        @if ($line['reason'])<span class="block text-sm text-text-secondary print:text-black">{{ __('Grund: :reason', ['reason' => $line['reason']]) }}</span>@endif
                    </span>
                </li>
            @endforeach
        </ol>
    </section>

    <p class="mt-10 text-sm text-text-tertiary print:text-black">{{ __('Erstellt am :date', ['date' => $this->stamp(now())]) }}</p>
</div>
