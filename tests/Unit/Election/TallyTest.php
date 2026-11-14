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
    ]);
});

it('elects with more than half of the votes for candidates, never with exactly half', function (array $spec, int $blank, array $expected) {
    expect(tallyDecision(ElectionCountingRule::SingleMajority, 1, [11, 12, 13], tallyRows($spec, $blank)))->toBe($expected);
})->with([
    '6 of 10' => [[11 => ['for' => 6], 12 => ['for' => 4]], 0, ['outcome' => 'complete', 'elected' => [11], 'tied' => [], 'tied_seats' => 0]],
    '5 of 10' => [[11 => ['for' => 5], 12 => ['for' => 3], 13 => ['for' => 2]], 0, ['outcome' => 'understaffed', 'elected' => [], 'tied' => [], 'tied_seats' => 0]],
    '5 of 10, level' => [[11 => ['for' => 5], 12 => ['for' => 5]], 0, ['outcome' => 'understaffed', 'elected' => [], 'tied' => [], 'tied_seats' => 0]],
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

    expect(tallyDecision(ElectionCountingRule::YesNoPerCandidate, 3, [11, 12, 13], $rows))
        ->toBe(['outcome' => 'understaffed', 'elected' => [11], 'tied' => [], 'tied_seats' => 0]);

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
