<?php

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionRoundState;
use App\Enums\ElectionTieKind;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\RoundReport;
use App\Support\ElectionTieText;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The view the board shares in the video call: the round that matters right
 * now, large and read-only. While a round is open it shows how many have
 * voted; after the seal, the result. It refreshes itself every few seconds,
 * so it can stay shared the whole evening.
 *
 * No control and no voter on this page — counts and candidate names only.
 */
new #[Layout('layouts.bare')] #[Title('Wahl – Ansicht zum Teilen')] class extends Component
{
    #[Locked]
    public int $electionId;

    /**
     * Takes the id, not the model: Livewire re-applies the route's middleware
     * on every poll, and a bound `{election}` would answer each poll with a
     * 404 once a test election is purged while this screen is still shared.
     */
    public function mount(int|string $election): void
    {
        $this->actor();

        $this->electionId = (int) Election::query()->findOrFail((int) $election)->id;
    }

    /**
     * Null once the election is gone — a test election purged while this
     * screen was still shared in the call. The screen then says so and stops
     * polling instead of answering every poll with a 404.
     */
    #[Computed]
    public function election(): ?Election
    {
        $this->actor();

        return Election::query()->find($this->electionId);
    }

    /**
     * Sort key of a finished round: the moment it ended, then first-level rounds before run-offs, then creation order.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function finishedOrder(ElectionRound $round): array
    {
        return [($round->annulled_at ?? $round->closed_at)->getTimestamp(), $round->parent_round_id === null ? 1 : 0, (int) $round->id];
    }

    /**
     * The round to show: the open one; else one whose time ran out; else the
     * one finished last; else the first that has not been opened yet.
     *
     * @return array{report: array<string, mixed>, number: int|null, total: int}|null
     */
    #[Computed]
    public function current(): ?array
    {
        $this->actor();

        if ($this->election === null) {
            return null;
        }

        $reports = app(RoundReport::class)->forElection($this->election);

        if ($reports->isEmpty()) {
            return null;
        }

        $report = $reports->first(fn (array $report): bool => $report['state'] === ElectionRoundState::Open)
            ?? $reports->first(fn (array $report): bool => $report['over_by_clock'])
            ?? $reports
                ->filter(fn (array $report): bool => $report['round']->opened_at !== null && ($report['round']->closed_at !== null || $report['round']->annulled_at !== null))
                // Latest finished first; within one second the round itself before a run-off annulled along with it, then the later created.
                ->sort(fn (array $a, array $b): int => $this->finishedOrder($b['round']) <=> $this->finishedOrder($a['round']))
                ->first()
            ?? $reports->first(fn (array $report): bool => $report['state'] === ElectionRoundState::Draft);

        if ($report === null) {
            return null;
        }

        // The count is the agenda: rounds of their own that still stand. A run-off
        // belongs to its round, an annulled round is void and its repeat takes
        // its place, so "Wahlgang 2 von 4" holds however often the board annuls.
        $agenda = $reports
            ->filter(fn (array $candidate): bool => $candidate['round']->parent_round_id === null && $candidate['state'] !== ElectionRoundState::Annulled)
            ->values();
        $number = $agenda->search(fn (array $candidate): bool => $candidate['round']->is($report['round']));

        return ['report' => $report, 'number' => $number === false ? null : $number + 1, 'total' => $agenda->count()];
    }

    protected function clock(?CarbonInterface $moment = null): string
    {
        return ($moment ?? now())->copy()->setTimezone(config('app.display_timezone'))->format('H:i');
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
@endphp

@php
    $current = $this->current;
    $report = $current['report'] ?? null;
    $round = $report['round'] ?? null;
    $result = $report['result'] ?? null;
    $isYesNo = $round?->counting_rule === ElectionCountingRule::YesNoPerCandidate;
@endphp

{{-- Type for a shared screen: sizes follow the viewport height, so the same page reads at 1280×720 and 1920×1080. --}}
<div @if ($election !== null) wire:poll.3s @endif
     class="election-ui flex min-h-screen flex-col px-[5vw] py-[4vh] [--s-meta:clamp(1.5rem,2.6vh,2rem)] [--s-body:clamp(1.5rem,3.4vh,2.5rem)] [--s-row:clamp(1.5rem,2.8vh,2rem)] [--s-state:clamp(1.5rem,4.5vh,3.25rem)] [--s-title:clamp(2rem,6.6vh,4.75rem)] [--s-figure:clamp(5rem,22vh,15rem)]"
     data-test="share-screen">
    @if ($election === null)
        <main class="flex flex-1 flex-col justify-center">
            <h1 class="text-[length:var(--s-title)] font-bold leading-tight" data-test="share-gone">{{ __('Diese Wahl gibt es nicht mehr.') }}</h1>
            <p class="mt-[3vh] text-[length:var(--s-body)] text-text-secondary">{{ __('Die Probewahl ist gelöscht. Ihr könnt dieses Fenster schließen.') }}</p>
        </main>
    @else
    <header class="flex flex-wrap items-baseline justify-between gap-x-8 gap-y-2 text-[length:var(--s-meta)] text-text-secondary">
        <p class="min-w-0 font-bold text-text-primary wrap-anywhere">EINUNDZWANZIG <span class="font-normal text-text-secondary">{{ $election->name }}</span></p>
        @if ($election->is_test)
            <p class="inline-flex items-center gap-2 font-bold text-warning" data-test="share-test-marker">
                <flux:icon.beaker class="size-[1.2em]" aria-hidden="true" />{{ __('Probewahl') }}
            </p>
        @endif
    </header>

    <main class="flex flex-1 flex-col justify-center py-[4vh]">
        @if ($round === null)
            <h1 class="text-[length:var(--s-title)] font-bold leading-tight">{{ __('Noch kein Wahlgang angelegt.') }}</h1>
        @else
            <p class="text-[length:var(--s-meta)] text-text-secondary" data-test="share-round-number">
                @if ($round->parent_round_id)
                    {{ __('Stichwahl') }}
                @elseif ($current['number'] === null)
                    {{ __('Annullierter Wahlgang, zählt nicht mit') }}
                @else
                    {{ __('Wahlgang :number von :total', ['number' => $current['number'], 'total' => $current['total']]) }}
                @endif
            </p>
            <h1 class="mt-[1vh] text-[length:var(--s-title)] font-bold leading-[1.1] break-words" data-test="share-round-title">{{ $round->title }}</h1>

            {{-- State: icon and word, never colour alone. --}}
            <p class="mt-[2vh] flex items-center gap-[0.4em] text-[length:var(--s-state)] font-bold" data-test="share-state">
                @switch(true)
                    @case($report['state'] === ElectionRoundState::Open)
                        <span class="block size-[0.55em] shrink-0 rounded-full bg-orange-500" aria-hidden="true"></span>
                        <span>{{ __('Offen. Stimmt jetzt auf der Wahlseite ab.') }}</span>
                        @break
                    @case($report['over_by_clock'])
                        <flux:icon.clock class="size-[1em] text-text-secondary" aria-hidden="true" />
                        <span>{{ __('Abstimmung beendet. Wird gleich ausgezählt.') }}</span>
                        @break
                    @case($report['state'] === ElectionRoundState::Annulled)
                        <flux:icon.no-symbol class="size-[1em] text-danger" aria-hidden="true" />
                        <span>{{ __('Annulliert. Dieser Wahlgang wird nicht ausgezählt.') }}</span>
                        @break
                    @case($report['sealed'])
                        <flux:icon.check-circle class="size-[1em] text-success" aria-hidden="true" />
                        <span>{{ __('Ausgezählt um :time Uhr', ['time' => $this->clock($round->closed_at)]) }}</span>
                        @break
                    @default
                        <flux:icon.pause-circle class="size-[1em] text-text-secondary" aria-hidden="true" />
                        <span>{{ __('Noch nicht geöffnet') }}</span>
                @endswitch
            </p>

            @if ($report['state'] === ElectionRoundState::Open || $report['over_by_clock'])
                {{-- Turnout: the count, then a tally — one stroke per vote cast, struck through in fives. --}}
                <div class="mt-[5vh]" data-test="share-turnout">
                    <p class="leading-none">
                        <span class="text-[length:var(--s-figure)] font-bold tabular-nums" data-test="share-cast">{{ $report['cast'] }}</span>
                        <span class="ml-[0.3em] text-[length:var(--s-title)] text-text-secondary">{{ __('von :roll', ['roll' => $report['roll']]) }}</span>
                    </p>
                    <p class="mt-[1.5vh] text-[length:var(--s-body)] text-text-secondary">{{ trans_choice('{1} hat abgestimmt|[0,*] haben abgestimmt', $report['cast']) }}</p>
                    @if ($report['cast'] > 0 && $report['cast'] <= 150)
                        <div class="mt-[3vh] flex flex-wrap gap-x-[1.6vh] gap-y-[1.2vh] text-text-primary" aria-hidden="true" data-test="share-tally">
                            @for ($group = 0; $group < intdiv($report['cast'] + 4, 5); $group++)
                                @php $strokes = min(5, $report['cast'] - $group * 5); @endphp
                                <svg viewBox="0 0 56 48" class="h-[6vh] w-auto" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round">
                                    @for ($stroke = 0; $stroke < min(4, $strokes); $stroke++)
                                        <line x1="{{ 8 + $stroke * 12 }}" y1="6" x2="{{ 8 + $stroke * 12 }}" y2="42" />
                                    @endfor
                                    @if ($strokes === 5)
                                        <line x1="2" y1="38" x2="52" y2="10" />
                                    @endif
                                </svg>
                            @endfor
                        </div>
                    @endif
                </div>
            @elseif ($result !== null)
                @php
                    $base = max(1, $report['cast']);
                    $elected = collect($result['rows'])->where('elected', true)->pluck('name');
                    // A name may end in a full stop ("Erik E."); the sentence must not add a second one.
                    $sentence = fn (string $text): string => preg_replace('/(?<=\S)\.\.(?=\s|$)/u', '.', $text);
                @endphp
                <div class="mt-[4vh]" data-test="share-result">
                    <p class="text-[length:var(--s-body)] font-bold break-words" data-test="share-outcome">
                        @switch($result['outcome'])
                            @case(ElectionOutcome::Complete)
                                {{ $sentence(trans_choice('{1} Gewählt ist :names.|[2,*] Gewählt sind :names.', $elected->count(), ['names' => $elected->join(', ', ' und ')])) }}
                                @break
                            @case(ElectionOutcome::Tie)
                                <span class="inline-flex items-center gap-[0.4em] text-warning"><flux:icon.scale class="size-[1.1em]" aria-hidden="true" />{{ $result['tie_kind'] === ElectionTieKind::YesEqualsNo ? $sentence(ElectionTieText::yesEqualsNo(collect($result['rows'])->where('tied', true)->pluck('name')->all())) : __('Gleichstand.') }}</span>
                                <span class="block" data-test="share-tie-next">{{ $report['run_off_resolved'] ? ElectionTieText::runOffResolved() : ElectionTieText::pending() }}</span>
                                @break
                            @default
                                <span class="inline-flex items-center gap-[0.4em] text-warning"><flux:icon.exclamation-triangle class="size-[1.1em]" aria-hidden="true" />{{ $elected->isEmpty() ? __('Niemand hat die nötige Mehrheit.') : $sentence(trans_choice('{1} Gewählt ist :names. Nicht alle Sitze sind besetzt.|[2,*] Gewählt sind :names. Nicht alle Sitze sind besetzt.', $elected->count(), ['names' => $elected->join(', ', ' und ')])) }}</span>
                        @endswitch
                    </p>
                    @if ($result['casting_vote'] !== null)
                        <p class="mt-[1.5vh] text-[length:var(--s-meta)] break-words" data-test="share-casting-vote">{{ $sentence(ElectionTieText::decidedBy($result['casting_vote']['chair'])) }}</p>
                    @endif

                    {{-- More than three rows are dense: below 800px height the bars give way to the numbers they repeat. On a landscape screen they go into two columns, unless a name is too long for half the width — a column holds about 38 characters per line at 1280×720, and a long name with long words then needs three lines. Such a list stays in one column, where the name gets the full width. Names are never cut. --}}
                    @php
                        $dense = count($result['rows']) > 3;
                        $split = $dense && collect($result['rows'])->max(fn (array $row): int => mb_strlen($row['name'])) <= 40;
                    @endphp
                    <ol @class([
                            'mt-[3vh]',
                            'space-y-[2vh]' => ! $dense,
                            'space-y-[1.5vh]' => $dense && ! $split,
                            'lg:grid lg:grid-flow-col lg:grid-cols-2 lg:gap-x-[4vw] lg:gap-y-[1.5vh] lg:space-y-0 lg:[grid-template-rows:repeat(var(--rows),auto)]' => $split,
                        ]) @if ($split) style="--rows: {{ (int) ceil(count($result['rows']) / 2) }}" @endif data-layout="{{ $split ? 'columns' : 'list' }}" data-test="share-rows">
                        @foreach ($result['rows'] as $row)
                            @php $value = $isYesNo ? $row['yes'] : $row['for']; @endphp
                            {{-- Wide, one column: name, votes and verdict on one line; the name takes what the figures leave and wraps there. Wide, two columns: the name owns its line and the figures follow below it, so every row has the same shape and a name gets the full column instead of a sliver. The bar runs below. Narrow: the verdict moves next to the bar. --}}
                            <li @class([
                                    'grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-[2vw] gap-y-[0.8vh] text-[length:var(--s-meta)] lg:flex lg:flex-wrap',
                                    '[--s-name:var(--s-body)]' => ! $dense,
                                    '[--s-name:var(--s-body)] lg:[--s-name:var(--s-row)]' => $dense,
                                ]) data-test="share-row">
                                <span @class(['min-w-0 text-[length:var(--s-name)] font-bold break-words', 'lg:basis-full' => $split, 'lg:flex-[1_1_0%]' => ! $split]) data-test="share-row-name">{{ $row['name'] }}</span>
                                <span @class(['contents lg:flex lg:items-baseline lg:gap-x-[2vw]', 'lg:ml-auto' => ! $split])>
                                    <span @class(['text-right text-[length:var(--s-name)] tabular-nums whitespace-nowrap lg:min-w-[6em]', 'lg:text-left' => $split])>
                                        @if ($isYesNo)
                                            {{ __(':yes Ja, :no Nein', ['yes' => $row['yes'], 'no' => $row['no']]) }}
                                        @else
                                            {{ trans_choice('{0} 0 Stimmen|{1} 1 Stimme|[2,*] :count Stimmen', $row['for'], ['count' => $row['for']]) }}
                                        @endif
                                    </span>
                                    {{-- Fixed widths keep votes and verdicts in columns from row to row; 8.25em holds „Gleichstand“ with its icon. The word, not the icon, gives the verdict its baseline: an svg's baseline is its bottom edge and would push the row 5px taller. --}}
                                    <span class="order-1 min-w-[7.5em] font-bold lg:order-none lg:min-w-[8.25em]">
                                        @if ($row['elected'])
                                            <span class="inline-flex items-center gap-[0.3em] text-success"><flux:icon.check-circle variant="mini" class="size-[1.2em]" aria-hidden="true" /><span class="self-baseline">{{ __('gewählt') }}</span></span>
                                        @elseif ($row['tied'])
                                            <span class="inline-flex items-center gap-[0.3em] text-warning"><flux:icon.scale variant="mini" class="size-[1.2em]" aria-hidden="true" /><span class="self-baseline">{{ __('Gleichstand') }}</span></span>
                                        @endif
                                    </span>
                                </span>
                                <span @class(['h-[1.4vh] self-center bg-neutral-800 lg:order-last lg:basis-full', 'lg:[@media(max-height:799px)]:hidden' => $dense]) aria-hidden="true"><span @class(['block h-full', 'bg-text-primary' => $row['elected'], 'bg-warning' => $row['tied'], 'bg-neutral-500' => ! $row['elected'] && ! $row['tied']]) style="width: {{ round($value / $base * 100, 1) }}%"></span></span>
                            </li>
                        @endforeach
                    </ol>

                    <p class="mt-[3vh] text-[length:var(--s-meta)] text-text-secondary">
                        {{ trans_choice('{1} :count von :roll hat abgestimmt,|[0,*] :count von :roll haben abgestimmt,', $report['cast'], ['roll' => $report['roll']]) }} {{ trans_choice('{0} davon keine Enthaltung (leer).|{1} davon 1 Enthaltung (leer).|[2,*] davon :count Enthaltungen (leer).', $result['blank']) }}
                    </p>
                </div>
            @elseif ($report['state'] === ElectionRoundState::Draft && $report['candidates'] !== [])
                <div class="mt-[4vh]">
                    <p class="text-[length:var(--s-body)] text-text-secondary">{{ __('Zur Wahl stehen') }}</p>
                    <ul class="mt-[2vh] space-y-[1vh] text-[length:var(--s-body)] font-bold">
                        @foreach ($report['candidates'] as $name)
                            <li class="break-words">{{ $name }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </main>

    <footer class="text-[length:var(--s-meta)] text-text-secondary">
        {{ __('Stand :time Uhr. Aktualisiert sich von selbst.', ['time' => $this->clock()]) }}
    </footer>
    @endif
</div>
