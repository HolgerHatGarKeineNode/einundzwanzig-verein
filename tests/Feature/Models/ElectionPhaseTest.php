<?php

use App\Models\Election;
use App\Models\ElectionRound;
use App\Support\NostrAuth;
use Livewire\Livewire;

require_once __DIR__.'/../Services/Election/helpers.php';

/*
 * The phase of an election as the members' page and the board's page read
 * it. Characterisation first: both pages carried their own rule, and these
 * cases pin what each of them answered.
 */

/**
 * @return array{member: string, board: int}
 */
function phasesOnBothPages(Election $election): array
{
    [$board] = electionBoard(1);
    NostrAuth::login($board->pubkey);

    $call = function (string $component) use ($election): mixed {
        $instance = Livewire::test($component)->instance();

        return (new ReflectionMethod($instance, 'phaseOf'))->invoke($instance, $election->fresh());
    };

    return ['member' => $call('association.election.index'), 'board' => $call('association.election.admin')];
}

/**
 * @param  array<string, mixed>  $state
 */
function roundIn(Election $election, array $state = [], ?int $parent = null): ElectionRound
{
    $round = draftElectionRound(['parent_round_id' => $parent], election: $election);
    $round->forceFill($state)->save();

    return $round;
}

it('answers per page as before', function (Closure $build, string $member, int $board) {
    expect(phasesOnBothPages($build()))->toBe(['member' => $member, 'board' => $board]);
})->with([
    'not announced, no round' => [fn () => Election::factory()->test()->create(), 'announced', 0],
    'not announced, a draft round' => [function () {
        $election = Election::factory()->test()->create();
        roundIn($election);

        return $election;
    }, 'candidacy', 0],
    // The two cases the pages disagree on: this one and the last.
    'announced, no round' => [fn () => Election::factory()->announced()->create(), 'announced', 2],
    'announced, a draft round' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election);

        return $election;
    }, 'candidacy', 2],
    'announced, a round opened' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['opened_at' => now()]);
        roundIn($election);

        return $election;
    }, 'meeting', 3],
    'announced, every round closed' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['opened_at' => now()->subHour(), 'closed_at' => now()]);

        return $election;
    }, 'result', 4],
    'announced, one round closed and one struck' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['opened_at' => now()->subHour(), 'closed_at' => now()]);
        roundIn($election, ['annulled_at' => now()]);

        return $election;
    }, 'result', 4],
    'announced, the only round annulled after opening' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['opened_at' => now()->subHour(), 'annulled_at' => now()]);

        return $election;
    }, 'result', 4],
    'announced, the only round struck before the meeting' => [function () {
        $election = Election::factory()->announced()->create();
        roundIn($election, ['annulled_at' => now()]);

        return $election;
    }, 'result', 2],
]);
