<?php

namespace App\Services\Election;

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionPhase;
use App\Enums\ElectionRoundState;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Support\DisplayTime;
use Carbon\CarbonInterface;

/**
 * The run sheet of an election on the board's page: the five stations from
 * draft to result, and the one next step.
 *
 * A step names what to do and why, and what does it: a section of the page
 * to show (`action`), a Livewire call (`click`) or a page (`href`); all null
 * when the step lies outside the page. Reads only; every step it offers is
 * taken by a component that asks the board guard itself.
 */
class RunSheet
{
    public function __construct(protected RefusalText $refusals) {}

    /**
     * The five stations, each done, current or ahead.
     *
     * @return list<array{label: string, when: string, state: 'done'|'current'|'ahead'}>
     */
    public function stationsOf(Election $election): array
    {
        $phase = $election->phase()->step();
        $labels = [
            [__('Entwurf'), __('angelegt :date', ['date' => $this->date($election->created_at)])],
            [__('Angekündigt'), $election->announced_at === null ? __('noch nicht') : $this->date($election->announced_at)],
            [__('Kandidatur'), __('bis zur Sitzung')],
            [__('Sitzung live'), $election->meeting_at === null ? __('Termin fehlt') : $this->date($election->meeting_at).', '.DisplayTime::of($election->meeting_at)->format('H:i')],
            [__('Ergebnis'), __('nach der Sitzung')],
        ];

        return array_map(fn (int $index): array => [
            'label' => $labels[$index][0],
            'when' => $labels[$index][1],
            'state' => match (true) {
                $index === $phase => 'current',
                $index < $phase => 'done',
                default => 'ahead',
            },
        ], array_keys($labels));
    }

    /**
     * The one next step.
     *
     * In the meeting it walks the board through the evening: open a round,
     * close it, show the result, set up a run-off if it ended level, open the
     * next round, and at the end the minutes. It never offers to open a round
     * `OpenRound` would refuse: it names the reason and the way out instead —
     * the candidacies, or striking a round nobody can stand in any more.
     *
     * @return array{text: string, hint: string|null, action: string|null, label: string|null, click: string|null, href: string|null, secondary_label: string|null, secondary_click: string|null}
     */
    public function nextStepOf(Election $election): array
    {
        // A struck round is void: it holds no step.
        $rounds = $election->rounds->whereNull('parent_round_id')->whereNull('annulled_at');
        $phase = $election->phase();

        if ($phase === ElectionPhase::Draft) {
            if ($rounds->isEmpty()) {
                return $this->step(__('Lege mindestens einen Wahlgang an.'), __('Ohne Wahlgang lässt sich die Wahl nicht ankündigen.'), action: 'settings', label: __('Wahlgang anlegen'));
            }

            if ($election->meeting_at === null) {
                return $this->step(__('Trage den Sitzungstermin ein.'), __('Gewählt wird live in dieser Sitzung. Mitglieder sehen den Termin mit der Ankündigung.'), action: 'settings', label: __('Termin eintragen'));
            }

            return $this->step(
                __('Wahl ankündigen.'),
                $election->is_test
                    ? __('Probewahl: auch angekündigt sieht sie nur der Vorstand.')
                    : __('Danach sehen alle Mitglieder die Wahl und können kandidieren.'),
                action: 'announce',
                label: __('Wahl ankündigen'),
            );
        }

        if ($phase === ElectionPhase::Candidacy) {
            $pending = $rounds->sum(fn (ElectionRound $round): int => $round->candidates->where('status', ElectionCandidateStatus::Pending)->count());

            if ($pending > 0) {
                return $this->step(
                    trans_choice('{1} Eine Kandidatur wartet auf Prüfung.|[2,*] :count Kandidaturen warten auf Prüfung.', $pending, ['count' => $pending]),
                    __('Freigeben geht, sobald die Person nach der Beitragsregel wahlberechtigt ist.'),
                    action: 'candidacies',
                    label: __('Kandidaturen prüfen'),
                );
            }

            $without = $rounds->filter(fn (ElectionRound $round): bool => $round->candidates->where('status', ElectionCandidateStatus::Approved)->isEmpty());
            $first = $rounds->sortBy('position')->first();

            // Candidacies stay open until the meeting: only the round that is opened first is a dead end before the evening.
            // A later round without a candidacy is mentioned on the ready step below, without the offer to strike it.
            $laterWithout = $without->reject(fn (ElectionRound $round): bool => $round->is($first))->sortBy('position')->values();
            $without = $without->first(fn (ElectionRound $round): bool => $round->is($first));

            if ($without !== null) {
                return $this->step(
                    __('„:round“ hat noch keine freigegebene Kandidatur.', ['round' => $without->title]),
                    __('Ohne freigegebene Kandidatur lässt sich ein Wahlgang in der Sitzung nicht öffnen. Kann niemand mehr antreten, streicht den Wahlgang.'),
                    action: 'candidacies',
                    label: __('Kandidaturen ansehen'),
                    secondaryLabel: __('Wahlgang streichen'),
                    secondaryClick: $this->roundCall('startRoundAction', $without, 'strike'),
                );
            }
        }

        $all = $election->rounds->sortBy('position')->values();
        $now = now();

        if ($all->isEmpty()) {
            return $this->step(
                __('Diese Wahl hat noch keinen Wahlgang.'),
                __('Legt unter „Einstellungen“ mindestens einen Wahlgang an.'),
                action: 'settings',
                label: __('Wahlgänge anlegen'),
            );
        }

        $open = $all->first(fn (ElectionRound $round): bool => $round->stateAt($now) === ElectionRoundState::Open);

        if ($open !== null) {
            return $this->step(
                __('„:round“ ist offen.', ['round' => $open->title]),
                __('Die Beteiligung steht unter „Sitzung“ und in der Ansicht zum Teilen. Schließt den Wahlgang, wenn alle abgestimmt haben, die abstimmen wollen.'),
                label: __('Wahlgang schließen'),
                click: $this->roundCall('startRoundAction', $open, 'close'),
            );
        }

        $overByClock = $all->first(fn (ElectionRound $round): bool => $round->stateAt($now) === ElectionRoundState::Closed && ! $round->isSealed());

        if ($overByClock !== null) {
            return $this->step(
                __('„:round“ nimmt nach :minutes Minuten keine Stimmen mehr an.', ['round' => $overByClock->title, 'minutes' => OpenRound::LIVE_MAX_MINUTES]),
                __('Zählt ihn aus, dann seht ihr das Ergebnis.'),
                label: __('Auszählen'),
                click: $this->roundCall('sealRound', $overByClock),
            );
        }

        $runOffs = app(CreateRunOff::class);
        $proposals = [];
        $level = $all->first(function (ElectionRound $round) use ($all, $runOffs, &$proposals): bool {
            if (! $round->isSealed() || $all->where('parent_round_id', $round->id)->whereNull('annulled_at')->isNotEmpty()) {
                return false;
            }

            $proposals[$round->id] = $runOffs->proposal($round);

            return $proposals[$round->id]['candidates'] !== [];
        });

        // A tie: the chair casts the deciding vote (statutes Art. 6); a run-off only if the assembly resolves one.
        if ($level !== null && $proposals[$level->id]['needs_resolution']) {
            return $this->step(
                __('„:round“ endet im Gleichstand.', ['round' => $level->title]),
                __('Nach den Statuten fällt die/der Vorsitzende den Stichentscheid. Tragt ihn ein, sobald er gefallen ist. Eine Stichwahl gibt es nur, wenn die Versammlung sie beschließt.'),
                label: __('Stichentscheid eintragen'),
                click: $this->roundCall('startCastingVote', $level),
                secondaryLabel: __('Stichwahl anlegen'),
                secondaryClick: $this->roundCall('startTieRunOff', $level),
            );
        }

        if ($level !== null) {
            return $this->step(
                __('„:round“ ist ohne Entscheidung ausgezählt.', ['round' => $level->title]),
                __('Zeigt das Ergebnis im Call. Die Stichwahl ist danach ein eigener Wahlgang zwischen den Betroffenen.'),
                label: __('Stichwahl anlegen'),
                click: $this->roundCall('createRunOff', $level),
            );
        }

        // An annulled round is void, also a run-off annulled along with its round before it was opened.
        $openable = $all->filter(fn (ElectionRound $round): bool => $round->opened_at === null && $round->annulled_at === null);
        $next = $openable->first(fn (ElectionRound $round): bool => $round->parent_round_id !== null) ?? $openable->first();
        $stewardship = app(ElectionStewardship::class);
        $awaiting = $all->first(fn (ElectionRound $round): bool => $stewardship->awaitsRepeat($round));

        if ($next !== null && ($refusal = app(OpenRound::class)->refusal($next)) !== null) {
            $withoutCandidates = str_contains($refusal->getMessage(), 'without approved candidates');
            $strike = $next->parent_round_id === null ? $this->roundCall('startRoundAction', $next, 'strike') : null;

            return $this->step(
                __('„:round“ lässt sich nicht öffnen.', ['round' => $next->title])
                    .($awaiting === null ? '' : ' '.__('„:round“ wartet noch auf die Wiederholung.', ['round' => $awaiting->title])),
                $this->refusals->of('open', $refusal, $election),
                action: $withoutCandidates && $strike !== null ? null : 'candidacies',
                label: $withoutCandidates && $strike !== null ? __('Wahlgang streichen') : __('Kandidaturen ansehen'),
                click: $withoutCandidates ? $strike : null,
                secondaryLabel: $strike === null ? null : ($withoutCandidates ? __('Kandidaturen ansehen') : __('Wahlgang streichen')),
                secondaryClick: $strike === null ? null : ($withoutCandidates ? "showSection('candidacies')" : $strike),
            );
        }

        if ($next !== null) {
            $last = $all->filter(fn (ElectionRound $round): bool => $round->opened_at !== null && ($round->closed_at !== null || $round->annulled_at !== null))
                // Latest finished first; within one second the round itself before a run-off annulled along with it, then the later created.
                ->sort(fn (ElectionRound $a, ElectionRound $b): int => [($b->annulled_at ?? $b->closed_at)->getTimestamp(), $b->parent_round_id === null ? 1 : 0, $b->id] <=> [($a->annulled_at ?? $a->closed_at)->getTimestamp(), $a->parent_round_id === null ? 1 : 0, $a->id])
                ->first();

            return $this->step(
                match (true) {
                    $last?->isSealed() === true => __('„:last“ ist ausgezählt. Als Nächstes: „:round“.', ['last' => $last->title, 'round' => $next->title]),
                    $last !== null => __('„:last“ ist annulliert. Als Nächstes: „:round“.', ['last' => $last->title, 'round' => $next->title]),
                    $election->meeting_at !== null && ! $election->is_test => __('Bereit für die Sitzung am :date. Zuerst: „:round“.', ['date' => $this->longDate($election->meeting_at), 'round' => $next->title]),
                    default => __('Bereit. Zuerst: „:round“.', ['round' => $next->title]),
                }.($awaiting === null ? '' : ' '.__('„:round“ wartet noch auf die Wiederholung.', ['round' => $awaiting->title])),
                match (true) {
                    $last?->isSealed() === true => $this->seatsLeftOpenText($last).__('Zeigt zuerst das Ergebnis im Call, dann öffnet den nächsten Wahlgang.'),
                    $election->is_test => __('Probewahl: öffnet den Wahlgang, wann immer ihr üben wollt.'),
                    default => __('Öffnet ihn erst in der Sitzung. Ab dann können die Wahlberechtigten abstimmen.'),
                }.(($laterWithout ?? collect())->isEmpty() ? '' : ' '.trans_choice(
                    '{1} „:rounds“ hat noch keine freigegebene Kandidatur. Kandidieren geht bis zur Sitzung.|[2,*] „:rounds“ haben noch keine freigegebene Kandidatur. Kandidieren geht bis zur Sitzung.',
                    $laterWithout->count(),
                    ['rounds' => $laterWithout->pluck('title')->join('“, „', '“ und „')],
                )),
                label: __('„:round“ öffnen', ['round' => $next->title]),
                click: $this->roundCall('startRoundAction', $next, 'open'),
            );
        }

        if ($awaiting !== null) {
            return $this->step(
                __('„:round“ ist annulliert und noch nicht wiederholt.', ['round' => $awaiting->title]),
                __('Wiederholt ihn, wenn neu abgestimmt werden soll, mit denselben freigegebenen Kandidaturen. Das Beschlussprotokoll findet ihr unter „Sitzung“.'),
                label: __('Wahlgang wiederholen'),
                click: $this->roundCall('startRoundAction', $awaiting, 'repeat'),
            );
        }

        return $this->step(
            __('Alle Wahlgänge sind abgeschlossen.'),
            $this->seatsLeftOpenText($all->filter(fn (ElectionRound $round): bool => $round->isSealed())->sortByDesc('closed_at')->first()).__('Das Beschlussprotokoll fasst Wahlgänge, Zahlen und Prüfsummen für die Niederschrift zusammen.'),
            label: __('Beschlussprotokoll öffnen'),
            href: route('association.election.admin.protocol', $election),
        );
    }

    /**
     * "Ein Sitz bleibt frei." for a counted round that left seats empty with
     * no run-off to follow (one candidate short of the majority, 0 votes, a
     * yes/no round with more no than yes), plus the only way to vote again.
     * Empty for any other round, so it can lead a hint.
     */
    public function seatsLeftOpenText(?ElectionRound $round): string
    {
        if ($round === null || ! $round->isSealed()) {
            return '';
        }

        $report = app(RoundReport::class)->of($round);
        $result = $report['result'];

        if ($result === null || $result['outcome'] !== ElectionOutcome::Understaffed || $report['run_off'] !== null || $report['has_run_off']) {
            return '';
        }

        $free = $round->seats - collect($result['rows'])->where('elected', true)->count();

        if ($free < 1) {
            return '';
        }

        return trans_choice('{1} In „:round“ bleibt ein Sitz frei.|[2,*] In „:round“ bleiben :count Sitze frei.', $free, ['round' => $round->title, 'count' => $free])
            .' '.__('Neu abstimmen geht nur über „Wahlgang annullieren“ und danach „Wahlgang wiederholen“.').' ';
    }

    /**
     * A date and time as the board reads it: "Sa, 20. März 2027, 19:00 Uhr".
     */
    public function longDate(CarbonInterface $moment): string
    {
        $local = DisplayTime::of($moment);

        return $local->locale('de')->isoFormat('dd, D. MMMM YYYY').', '.$local->format('H:i').' '.__('Uhr');
    }

    /**
     * The Livewire call of a round step.
     */
    protected function roundCall(string $method, ElectionRound $round, ?string $kind = null): string
    {
        return $kind === null ? "{$method}({$round->id})" : "{$method}({$round->id}, '{$kind}')";
    }

    protected function date(?CarbonInterface $moment): string
    {
        return $moment === null ? '' : DisplayTime::of($moment)->format('d.m.Y');
    }

    /**
     * @return array{text: string, hint: string|null, action: string|null, label: string|null, click: string|null, href: string|null, secondary_label: string|null, secondary_click: string|null}
     */
    protected function step(string $text, ?string $hint = null, ?string $action = null, ?string $label = null, ?string $click = null, ?string $href = null, ?string $secondaryLabel = null, ?string $secondaryClick = null): array
    {
        return ['text' => $text, 'hint' => $hint, 'action' => $action, 'label' => $label, 'click' => $click, 'href' => $href, 'secondary_label' => $secondaryLabel, 'secondary_click' => $secondaryClick];
    }
}
