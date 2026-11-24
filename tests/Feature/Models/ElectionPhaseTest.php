<?php

use App\Enums\ElectionPhase;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\RunSheet;
use App\Support\NostrAuth;
use Livewire\Livewire;

require_once __DIR__.'/../Services/Election/helpers.php';

/*
 * The phase of an election. The members' page and the board's page each
 * carried their own rule until 2026-11-24; they disagreed on two cases,
 * marked below. One rule now: "result" needs an opened round (the board's
 * reading), an announced election without an office is "announced" (the
 * members' reading, pinned in IndexTest). The members' page reads the
 * rounds alone, so the board can rehearse an unannounced test election
 * there; the board's run sheet calls such an election a draft.
 */

/**
 * @param  array<string, mixed>  $state
 */
function roundIn(Election $election, array $state = [], ?int $parent = null): ElectionRound
{
    $round = draftElectionRound(['parent_round_id' => $parent], election: $election);
    $round->forceFill($state)->save();

    return $round;
}

dataset('election phases', [
    'not announced, no round' => [fn () => Election::factory()->test()->create(), ElectionPhase::Announced, ElectionPhase::Draft],
    'not announced, a draft round' => [function () {
        $election = Election::factory()->test()->create();
        roundIn($election);

        return $election;
    }, ElectionPhase::Candidacy, ElectionPhase::Draft],
    'not announced, a round opened' => [function () {
        $election = Election::factory()->test()->create();
        roundIn($election, ['opened_at' => now()]);

        return $election;
    }, ElectionPhase::Meeting, ElectionPhase::Meeting],
    // Was: board "candidacy".
    'announced, no round' => [fn () => Election::factory()->announced()->create(), ElectionPhase::Announced, ElectionPhase::Announced],
    'announced, a draft round' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election);

        return $election;
    }, ElectionPhase::Candidacy, ElectionPhase::Candidacy],
    'announced, a round opened' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['opened_at' => now()]);
        roundIn($election);

        return $election;
    }, ElectionPhase::Meeting, ElectionPhase::Meeting],
    'announced, every round closed' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['opened_at' => now()->subHour(), 'closed_at' => now()]);

        return $election;
    }, ElectionPhase::Result, ElectionPhase::Result],
    'announced, one round closed and one struck' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['opened_at' => now()->subHour(), 'closed_at' => now()]);
        roundIn($election, ['annulled_at' => now()]);

        return $election;
    }, ElectionPhase::Result, ElectionPhase::Result],
    'announced, the only round annulled after opening' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['opened_at' => now()->subHour(), 'annulled_at' => now()]);

        return $election;
    }, ElectionPhase::Result, ElectionPhase::Result],
    // Was: members "result".
    'announced, the only round struck before the meeting' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['annulled_at' => now()]);

        return $election;
    }, ElectionPhase::Candidacy, ElectionPhase::Candidacy],
]);

it('derives one phase from the rounds and the announcement', function (Closure $build, ElectionPhase $byRounds, ElectionPhase $runSheet) {
    $election = $build()->fresh();

    expect($election->phaseOfRounds())->toBe($byRounds)
        ->and($election->phase())->toBe($runSheet);
})->with('election phases');

it('reads the same phase with the rounds loaded or not', function (Closure $build, ElectionPhase $byRounds, ElectionPhase $runSheet) {
    $election = $build()->fresh()->load('rounds');

    expect($election->phaseOfRounds())->toBe($byRounds)
        ->and($election->phase())->toBe($runSheet);
})->with('election phases');

it('shows members the phase by rounds and the board its run sheet', function (Closure $build, ElectionPhase $byRounds, ElectionPhase $runSheet) {
    $election = $build();
    [$board] = electionBoard(1);
    NostrAuth::login($board->pubkey);

    $member = Livewire::test('association.election.index', ['electionId' => $election->id]);
    $current = collect(app(RunSheet::class)->stationsOf($election->fresh()))
        ->search(fn (array $station): bool => $station['state'] === 'current');

    expect($member->instance()->phase)->toBe($byRounds->value)
        ->and($current)->toBe($runSheet->step());
})->with('election phases');
