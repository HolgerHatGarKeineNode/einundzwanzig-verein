<?php

namespace App\Services\Election;

use App\Enums\ElectionChoice;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionTieKind;
use UnexpectedValueException;

/**
 * Count the ballot rows of a round. Pure: no database, no clock, no
 * framework — the same rows always give the same result.
 *
 * Who is elected, per rule:
 *
 *  - `single_majority`: the candidate with MORE than half of the votes cast
 *    for candidates. Blank ballots are abstentions and not part of that base.
 *    Nobody above half → nobody elected (understaffed; the run-off of the two
 *    best placed is a round of its own) — unless the candidates sharing the
 *    top score together hold every vote cast for candidates (5:5, 3:3:3):
 *    that is a Stimmengleichheit, a tie between them for the open seat.
 *  - `yes_no_per_candidate`: every candidate with more yes than no. Should
 *    more qualify than there are seats, those with the most yes votes take
 *    them. A candidate with as many yes as no (at least one) is tied on their
 *    own confirmation for whatever seats the qualified leave over.
 *  - `most_votes`: the candidates named most often, at least once.
 *
 * Wherever the last seats would have to be split among candidates with the
 * same number of votes, nobody among them is elected and the result is a
 * tie. The order of candidates never decides anything, and neither does this
 * class: a tie stays a tie until the chair's casting vote is recorded
 * (statutes Art. 6, `RecordCastingVote`).
 */
class Tally
{
    /**
     * @param  list<int>  $candidateIds  the candidates of the round
     * @param  iterable<array{candidate: int|null, choice: string}>  $rows  the round's ballot rows
     *
     * @throws UnexpectedValueException when a row does not fit the round — a corrupted ballot table is not counted
     */
    public function count(ElectionCountingRule $rule, int $seats, array $candidateIds, iterable $rows): TallyResult
    {
        sort($candidateIds);

        $counts = array_fill_keys($candidateIds, ['for' => 0, 'yes' => 0, 'no' => 0]);
        $blank = 0;

        foreach ($rows as $row) {
            $choice = ElectionChoice::tryFrom((string) $row['choice']);
            $candidate = $row['candidate'] === null ? null : (int) $row['candidate'];

            if ($choice === ElectionChoice::Blank && $candidate === null) {
                $blank++;

                continue;
            }

            if ($choice === null || $candidate === null || ! array_key_exists($candidate, $counts) || ! in_array($choice, $rule->allowedChoices(), true)) {
                throw new UnexpectedValueException('A ballot row does not fit the rule or the candidates of its round.');
            }

            $counts[$candidate][$choice->value]++;
        }

        [$qualified, $score] = $this->qualified($rule, $counts);
        [$elected, $tied, $tiedSeats] = $this->allocate($qualified, $score, max(0, $seats));
        $tieKind = $tied === [] ? null : ElectionTieKind::Candidates;

        if ($tied === [] && count($elected) < $seats) {
            [$tied, $tieKind] = $this->stimmengleichheit($rule, $counts);
            $tiedSeats = $tied === [] ? 0 : $seats - count($elected);
        }

        $outcome = match (true) {
            $tied !== [] => ElectionOutcome::Tie,
            count($elected) < $seats => ElectionOutcome::Understaffed,
            default => ElectionOutcome::Complete,
        };

        return new TallyResult($rule, $seats, $outcome, $counts, $blank, $elected, $tied, $tiedSeats, $tieKind);
    }

    /**
     * The candidates who may take a seat at all, and the number that ranks
     * them for it.
     *
     * @param  array<int, array{for: int, yes: int, no: int}>  $counts
     * @return array{0: list<int>, 1: array<int, int>}
     */
    protected function qualified(ElectionCountingRule $rule, array $counts): array
    {
        $votesForCandidates = array_sum(array_column($counts, 'for'));

        $qualified = array_keys(array_filter($counts, fn (array $count): bool => match ($rule) {
            // Strictly more than half: 5 of 10 is not a majority.
            ElectionCountingRule::SingleMajority => $votesForCandidates < $count['for'] * 2,
            ElectionCountingRule::YesNoPerCandidate => $count['yes'] > $count['no'],
            ElectionCountingRule::MostVotes => $count['for'] > 0,
        }));

        $score = array_map(fn (array $count): int => match ($rule) {
            ElectionCountingRule::SingleMajority, ElectionCountingRule::MostVotes => $count['for'],
            ElectionCountingRule::YesNoPerCandidate => $count['yes'],
        }, $counts);

        return [$qualified, $score];
    }

    /**
     * The ties no seat allocation shows, for the seats still open after it:
     * an even split of every vote among the best placed in a single-majority
     * round, and yes = no on a candidate's confirmation.
     *
     * @param  array<int, array{for: int, yes: int, no: int}>  $counts
     * @return array{0: list<int>, 1: ElectionTieKind|null}
     */
    protected function stimmengleichheit(ElectionCountingRule $rule, array $counts): array
    {
        if ($rule === ElectionCountingRule::SingleMajority) {
            $top = $counts === [] ? 0 : max(array_column($counts, 'for'));
            $level = array_keys(array_filter($counts, fn (array $count): bool => $count['for'] === $top));

            // 4:4:2 is no Stimmengleichheit: the two best placed do not hold every vote.
            return $top > 0 && count($level) >= 2 && $top * count($level) === array_sum(array_column($counts, 'for'))
                ? [$level, ElectionTieKind::Candidates]
                : [[], null];
        }

        if ($rule === ElectionCountingRule::YesNoPerCandidate) {
            $level = array_keys(array_filter($counts, fn (array $count): bool => $count['yes'] > 0 && $count['yes'] === $count['no']));

            return $level === [] ? [[], null] : [$level, ElectionTieKind::YesEqualsNo];
        }

        return [[], null];
    }

    /**
     * Hand `$seats` seats to the qualified candidates by score.
     *
     * If the candidates on the last seat and the first one below it have the
     * same score, every candidate with that score is tied for whatever seats
     * the higher-ranked ones leave over.
     *
     * @param  list<int>  $qualified
     * @param  array<int, int>  $score
     * @return array{0: list<int>, 1: list<int>, 2: int}
     */
    protected function allocate(array $qualified, array $score, int $seats): array
    {
        if ($seats === 0) {
            return [[], [], 0];
        }

        if (count($qualified) <= $seats) {
            return [$qualified, [], 0];
        }

        $ranked = $qualified;
        usort($ranked, fn (int $a, int $b): int => $score[$b] <=> $score[$a]);

        $last = $score[$ranked[$seats - 1]];
        $firstBelow = $score[$ranked[$seats]];

        if ($firstBelow < $last) {
            $elected = array_slice($ranked, 0, $seats);
            sort($elected);

            return [$elected, [], 0];
        }

        $elected = array_values(array_filter($qualified, fn (int $id): bool => $score[$id] > $last));
        $tied = array_values(array_filter($qualified, fn (int $id): bool => $score[$id] === $last));

        return [$elected, $tied, $seats - count($elected)];
    }
}
