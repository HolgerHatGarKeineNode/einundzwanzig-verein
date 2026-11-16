<?php

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Services\Election\Tally;

/*
 * The count as arithmetic. Pure: no database, no framework. Every expected
 * number is written out by hand.
 *
 * Mutations that must turn this file red (patches in the plan folder):
 *  - M6c  most_votes counts one vote per candidate instead of one per row
 *  - M7a  equal votes on the last seat: take the first of the sorted list
 *  - B1-T1 single majority counts half as a majority (`>=` for `>`)
 *  - B1-T2 a round with fewer elected than seats is reported as complete
 */

/**
 * Ballot rows from a compact spec: candidate id => [choice => how many].
 *
 * @param  array<int, array<string, int>>  $spec
 * @return list<array{candidate: int|null, choice: string}>
 */
function tallyRows(array $spec, int $blank = 0): array
{
    $rows = [];

    foreach ($spec as $candidate => $choices) {
        foreach ($choices as $choice => $times) {
            for ($i = 0; $i < $times; $i++) {
                $rows[] = ['candidate' => $candidate, 'choice' => $choice];
            }
        }
    }

    for ($i = 0; $i < $blank; $i++) {
        $rows[] = ['candidate' => null, 'choice' => 'blank'];
    }

    // The order of rows means nothing; shuffled so no test leans on it.
    shuffle($rows);

    return $rows;
}

/**
 * @param  list<int>  $candidates
 * @param  list<array{candidate: int|null, choice: string}>  $rows
 * @return array{outcome: string, elected: list<int>, tied: list<int>, tied_seats: int}
 */
function tallyDecision(ElectionCountingRule $rule, int $seats, array $candidates, array $rows): array
{
    $result = (new Tally)->count($rule, $seats, $candidates, $rows)->toArray();

    return [
        'outcome' => $result['outcome'],
        'elected' => $result['elected'],
        'tied' => $result['tied'],
        'tied_seats' => $result['tied_seats'],
    ];
}

it('counts per candidate and choice, and blank ballots apart', function () {
    $result = (new Tally)->count(
        ElectionCountingRule::SingleMajority,
        1,
        [12, 11, 13],
        tallyRows([11 => ['for' => 3], 12 => ['for' => 2]], blank: 2),
    );

    expect($result->toArray())->toBe([
        'rule' => 'single_majority',
        'seats' => 1,
        'outcome' => 'complete',
        'counts' => [
            11 => ['for' => 3, 'yes' => 0, 'no' => 0],
            12 => ['for' => 2, 'yes' => 0, 'no' => 0],
            13 => ['for' => 0, 'yes' => 0, 'no' => 0],
        ],
        'blank' => 2,
        'elected' => [11],
        'tied' => [],
        'tied_seats' => 0,
        'tie_kind' => null,
    ]);
});

it('elects with more than half of the votes for candidates, never with exactly half', function (array $spec, int $blank, array $expected) {
    expect(tallyDecision(ElectionCountingRule::SingleMajority, 1, [11, 12, 13], tallyRows($spec, $blank)))->toBe($expected);
})->with([
    '6 of 10' => [[11 => ['for' => 6], 12 => ['for' => 4]], 0, ['outcome' => 'complete', 'elected' => [11], 'tied' => [], 'tied_seats' => 0]],
    '5 of 10' => [[11 => ['for' => 5], 12 => ['for' => 3], 13 => ['for' => 2]], 0, ['outcome' => 'understaffed', 'elected' => [], 'tied' => [], 'tied_seats' => 0]],
    // An even split of every vote is a Stimmengleichheit (statutes Art. 6): a tie, not a missed majority.
    '5 of 10, level' => [[11 => ['for' => 5], 12 => ['for' => 5]], 0, ['outcome' => 'tie', 'elected' => [], 'tied' => [11, 12], 'tied_seats' => 1]],
    // Blank ballots are abstentions: 4 of 7 votes for candidates is a majority although 4 of 11 ballots is not.
    '4 of 7 with 4 blank' => [[11 => ['for' => 4], 12 => ['for' => 3]], 4, ['outcome' => 'complete', 'elected' => [11], 'tied' => [], 'tied_seats' => 0]],
    'only blank ballots' => [[], 3, ['outcome' => 'understaffed', 'elected' => [], 'tied' => [], 'tied_seats' => 0]],
    'no ballots' => [[], 0, ['outcome' => 'understaffed', 'elected' => [], 'tied' => [], 'tied_seats' => 0]],
]);

it('elects every candidate with more yes than no', function () {
    $rows = tallyRows([
        11 => ['yes' => 4, 'no' => 3],
        12 => ['yes' => 3, 'no' => 3],
        13 => ['yes' => 2, 'no' => 5],
    ], blank: 1);

    // 12 has as many yes as no: tied on the confirmation for the two seats 11 leaves over.
    expect(tallyDecision(ElectionCountingRule::YesNoPerCandidate, 3, [11, 12, 13], $rows))
        ->toBe(['outcome' => 'tie', 'elected' => [11], 'tied' => [12], 'tied_seats' => 2]);

    expect(tallyDecision(ElectionCountingRule::YesNoPerCandidate, 1, [11, 12], tallyRows([11 => ['yes' => 1], 12 => ['no' => 1]])))
        ->toBe(['outcome' => 'complete', 'elected' => [11], 'tied' => [], 'tied_seats' => 0]);
});

it('gives the seats to the most yes votes when more candidates qualify than there are seats', function () {
    $rows = tallyRows([
        11 => ['yes' => 6, 'no' => 1],
        12 => ['yes' => 5, 'no' => 0],
        13 => ['yes' => 5, 'no' => 2],
    ]);

    expect(tallyDecision(ElectionCountingRule::YesNoPerCandidate, 1, [11, 12, 13], $rows))
        ->toBe(['outcome' => 'complete', 'elected' => [11], 'tied' => [], 'tied_seats' => 0])
        ->and(tallyDecision(ElectionCountingRule::YesNoPerCandidate, 2, [11, 12, 13], $rows))
        ->toBe(['outcome' => 'tie', 'elected' => [11], 'tied' => [12, 13], 'tied_seats' => 1]);
});

it('counts one vote per named candidate in a multi-seat round', function () {
    // Four ballots naming two candidates each, one naming a single candidate.
    $rows = tallyRows([11 => ['for' => 4], 12 => ['for' => 3], 13 => ['for' => 2], 14 => ['for' => 0]]);

    $result = (new Tally)->count(ElectionCountingRule::MostVotes, 2, [11, 12, 13, 14], $rows)->toArray();

    expect(array_map(fn (array $count): int => $count['for'], $result['counts']))->toBe([11 => 4, 12 => 3, 13 => 2, 14 => 0])
        ->and($result['elected'])->toBe([11, 12])
        ->and($result['outcome'])->toBe('complete');
});

it('reports level candidates on the last seat as a tie and elects none of them', function (int $seats, array $spec, array $expected) {
    expect(tallyDecision(ElectionCountingRule::MostVotes, $seats, [11, 12, 13, 14], tallyRows($spec)))->toBe($expected);
})->with([
    'two level for one seat' => [1, [11 => ['for' => 3], 12 => ['for' => 3], 13 => ['for' => 1]], ['outcome' => 'tie', 'elected' => [], 'tied' => [11, 12], 'tied_seats' => 1]],
    '5-4-3-3 for three seats' => [3, [11 => ['for' => 5], 12 => ['for' => 4], 13 => ['for' => 3], 14 => ['for' => 3]], ['outcome' => 'tie', 'elected' => [11, 12], 'tied' => [13, 14], 'tied_seats' => 1]],
    // Listed in the opposite order: the lower id must not win by position.
    '3-3-5-4 for three seats' => [3, [14 => ['for' => 4], 13 => ['for' => 5], 12 => ['for' => 3], 11 => ['for' => 3]], ['outcome' => 'tie', 'elected' => [13, 14], 'tied' => [11, 12], 'tied_seats' => 1]],
    'three level for two seats' => [2, [11 => ['for' => 2], 12 => ['for' => 2], 13 => ['for' => 2]], ['outcome' => 'tie', 'elected' => [], 'tied' => [11, 12, 13], 'tied_seats' => 2]],
    'level but enough seats for all' => [3, [11 => ['for' => 5], 12 => ['for' => 3], 13 => ['for' => 3]], ['outcome' => 'complete', 'elected' => [11, 12, 13], 'tied' => [], 'tied_seats' => 0]],
]);

it('ends understaffed when fewer candidates are elected than there are seats', function () {
    expect(tallyDecision(ElectionCountingRule::MostVotes, 3, [11, 12, 13], tallyRows([11 => ['for' => 4], 12 => ['for' => 1]])))
        ->toBe(['outcome' => 'understaffed', 'elected' => [11, 12], 'tied' => [], 'tied_seats' => 0])
        ->and(tallyDecision(ElectionCountingRule::MostVotes, 2, [11, 12], tallyRows([], blank: 5)))
        ->toBe(['outcome' => 'understaffed', 'elected' => [], 'tied' => [], 'tied_seats' => 0]);
});

it('refuses to count a row that does not fit the round', function (array $row) {
    expect(fn () => (new Tally)->count(ElectionCountingRule::MostVotes, 2, [11, 12], [$row]))
        ->toThrow(UnexpectedValueException::class, 'A ballot row does not fit the rule or the candidates of its round.');
})->with([
    'unknown candidate' => [['candidate' => 99, 'choice' => 'for']],
    'choice of another rule' => [['candidate' => 11, 'choice' => 'yes']],
    'unknown choice' => [['candidate' => 11, 'choice' => 'maybe']],
    'blank naming a candidate' => [['candidate' => 11, 'choice' => 'blank']],
    'vote without a candidate' => [['candidate' => null, 'choice' => 'for']],
]);

it('gives the same result for the same rows in any order', function () {
    $rows = tallyRows([11 => ['for' => 5], 12 => ['for' => 4], 13 => ['for' => 3], 14 => ['for' => 3]], blank: 2);
    $first = (new Tally)->count(ElectionCountingRule::MostVotes, 3, [11, 12, 13, 14], $rows)->toArray();

    expect((new Tally)->count(ElectionCountingRule::MostVotes, 3, [14, 13, 12, 11], array_reverse($rows))->toArray())->toBe($first)
        ->and($first['outcome'])->toBe(ElectionOutcome::Tie->value);
});

/*
 * Stimmengleichheit (statutes Art. 6: „Bei Stimmengleichheit fällt die/der
 * Vorsitzende den Stichentscheid.“, jury run 1, B1). A tie is a result state,
 * and the kind of tie decides how the chair's casting vote may look.
 *
 * Mutations that must turn this red: drop the "together hold every vote"
 * condition (4:4:2 becomes a tie); drop the yes = no recognition; mark every
 * tie as a tie between candidates.
 */
it('recognises a Stimmengleichheit in a single-majority round only when the level best placed hold every vote', function (array $spec, int $blank, array $expected) {
    $result = (new Tally)->count(ElectionCountingRule::SingleMajority, 1, [11, 12, 13], tallyRows($spec, $blank))->toArray();

    expect([$result['outcome'], $result['elected'], $result['tied'], $result['tied_seats'], $result['tie_kind']])->toBe($expected);
})->with([
    '5:5' => [[11 => ['for' => 5], 12 => ['for' => 5]], 0, ['tie', [], [11, 12], 1, 'candidates']],
    '5:5 with blank ballots and a candidate without votes' => [[11 => ['for' => 5], 12 => ['for' => 5]], 3, ['tie', [], [11, 12], 1, 'candidates']],
    '3:3:3' => [[11 => ['for' => 3], 12 => ['for' => 3], 13 => ['for' => 3]], 0, ['tie', [], [11, 12, 13], 1, 'candidates']],
    // No Stimmengleichheit: the absolute-majority run-off of the best placed stays as it is.
    '5:3:2' => [[11 => ['for' => 5], 12 => ['for' => 3], 13 => ['for' => 2]], 0, ['understaffed', [], [], 0, null]],
    '4:4:2' => [[11 => ['for' => 4], 12 => ['for' => 4], 13 => ['for' => 2]], 0, ['understaffed', [], [], 0, null]],
    'only blank ballots' => [[], 4, ['understaffed', [], [], 0, null]],
]);

it('recognises yes = no on a confirmation as a tie for the seats the qualified leave over', function (int $seats, array $spec, array $expected) {
    $result = (new Tally)->count(ElectionCountingRule::YesNoPerCandidate, $seats, [11, 12, 13], tallyRows($spec))->toArray();

    expect([$result['outcome'], $result['elected'], $result['tied'], $result['tied_seats'], $result['tie_kind']])->toBe($expected);
})->with([
    'one seat, 10 yes : 10 no' => [1, [11 => ['yes' => 10, 'no' => 10]], ['tie', [], [11], 1, 'yes_equals_no']],
    'two qualified and one yes = no for three seats' => [3, [11 => ['yes' => 6, 'no' => 2], 12 => ['yes' => 5, 'no' => 1], 13 => ['yes' => 4, 'no' => 4]], ['tie', [11, 12], [13], 1, 'yes_equals_no']],
    'qualified fill every seat: yes = no does not matter' => [2, [11 => ['yes' => 6, 'no' => 2], 12 => ['yes' => 5, 'no' => 1], 13 => ['yes' => 4, 'no' => 4]], ['complete', [11, 12], [], 0, null]],
    '0 : 0 is no tie' => [1, [11 => ['no' => 0]], ['understaffed', [], [], 0, null]],
    'more no than yes' => [1, [11 => ['yes' => 3, 'no' => 4]], ['understaffed', [], [], 0, null]],
    'level yes among the qualified is a tie between candidates' => [1, [11 => ['yes' => 5, 'no' => 1], 12 => ['yes' => 5, 'no' => 2]], ['tie', [], [11, 12], 1, 'candidates']],
]);

it('keeps level scores on the last seats of a multi-seat round a tie between candidates', function () {
    $result = (new Tally)->count(ElectionCountingRule::MostVotes, 3, [11, 12, 13, 14], tallyRows([11 => ['for' => 5], 12 => ['for' => 4], 13 => ['for' => 3], 14 => ['for' => 3]]))->toArray();

    expect([$result['outcome'], $result['elected'], $result['tied'], $result['tied_seats'], $result['tie_kind']])
        ->toBe(['tie', [11, 12], [13, 14], 1, 'candidates']);
});
