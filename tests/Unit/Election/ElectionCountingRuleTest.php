<?php

use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionChoice;
use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionBallotRefusedException;
use App\Models\ElectionReceipt;

/*
 * What a valid ballot is, per counting rule. Pure: no database, no framework.
 * `CastBallot` hands the frozen rule, the round's candidates and its seat
 * count to `ballotRows()` and writes exactly what comes back.
 */

/**
 * @return list<array{0: int|null, 1: string}>
 */
function ballotRowsOf(ElectionCountingRule $rule, array $selection, array $candidates = [11, 12, 13], int $seats = 2): array
{
    return array_map(
        fn (array $row): array => [$row['candidate'], $row['choice']->value],
        $rule->ballotRows($selection, $candidates, $seats),
    );
}

function refusalOf(Closure $cast): ?ElectionBallotRefusal
{
    try {
        $cast();
    } catch (ElectionBallotRefusedException $exception) {
        return $exception->reason;
    }

    return null;
}

it('turns an empty selection into one blank row under every rule', function (ElectionCountingRule $rule) {
    expect(ballotRowsOf($rule, []))->toBe([[null, ElectionChoice::Blank->value]]);
})->with(ElectionCountingRule::cases());

it('writes one row per chosen candidate', function () {
    expect(ballotRowsOf(ElectionCountingRule::SingleMajority, [12 => 'for']))->toBe([[12, 'for']])
        ->and(ballotRowsOf(ElectionCountingRule::MostVotes, [11 => 'for', 13 => 'for']))->toBe([[11, 'for'], [13, 'for']])
        ->and(ballotRowsOf(ElectionCountingRule::YesNoPerCandidate, [11 => 'yes', 12 => 'no']))->toBe([[11, 'yes'], [12, 'no']]);
});

it('lets a member vote on fewer candidates than there are', function () {
    expect(ballotRowsOf(ElectionCountingRule::YesNoPerCandidate, [13 => 'no']))->toBe([[13, 'no']])
        ->and(ballotRowsOf(ElectionCountingRule::MostVotes, [11 => 'for']))->toBe([[11, 'for']]);
});

it('refuses a selection the rule does not allow', function (ElectionCountingRule $rule, array $selection) {
    expect(refusalOf(fn () => $rule->ballotRows($selection, [11, 12, 13], 2)))
        ->toBe(ElectionBallotRefusal::InvalidChoices);
})->with([
    'two votes where one is allowed' => [ElectionCountingRule::SingleMajority, [11 => 'for', 12 => 'for']],
    'more votes than seats' => [ElectionCountingRule::MostVotes, [11 => 'for', 12 => 'for', 13 => 'for']],
    'a candidate of another round' => [ElectionCountingRule::SingleMajority, [99 => 'for']],
    'a candidate id as text' => [ElectionCountingRule::SingleMajority, ['11 ' => 'for']],
    'yes where candidates are picked' => [ElectionCountingRule::SingleMajority, [11 => 'yes']],
    'for where yes or no is asked' => [ElectionCountingRule::YesNoPerCandidate, [11 => 'for']],
    'blank next to a candidate' => [ElectionCountingRule::MostVotes, [11 => 'blank']],
    'an unknown choice' => [ElectionCountingRule::YesNoPerCandidate, [11 => 'maybe']],
    'a choice that is not text' => [ElectionCountingRule::YesNoPerCandidate, [11 => true]],
    'a nested selection' => [ElectionCountingRule::MostVotes, [11 => ['for']]],
]);

it('says nothing but the reason when it refuses', function () {
    try {
        ElectionCountingRule::SingleMajority->ballotRows([99 => 'for'], [11], 1);
    } catch (ElectionBallotRefusedException $exception) {
        expect($exception->getMessage())->toBe('Ballot refused: invalid_choices');

        return;
    }

    $this->fail('The ballot was not refused.');
});

it('binds a receipt hash to its round', function () {
    $code = str_repeat('ab', 16);

    expect(ElectionReceipt::hashFor(1, $code))
        ->toBe(hash('sha256', 'einundzwanzig-election-receipt:1:'.$code))
        ->not->toBe(ElectionReceipt::hashFor(2, $code));
});

it('accepts only 32 lowercase hex characters as a receipt code', function (string $code, int $matches) {
    expect(preg_match(ElectionReceipt::CODE_PATTERN, $code))->toBe($matches);
})->with([
    'a 128 bit code' => [str_repeat('0f', 16), 1],
    'uppercase' => [str_repeat('0F', 16), 0],
    'too short' => [str_repeat('0f', 15), 0],
    'a trailing newline' => [str_repeat('0f', 16)."\n", 0],
    'empty' => ['', 0],
]);
