<?php

use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Services\Election\CloseRound;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';

/*
 * What one poll of an election page costs in queries, at an evening of six
 * sealed rounds. The share view polls every second for the whole evening,
 * the member page every five seconds for every member in the call.
 *
 * Measured before these bounds (2026-11-24, six sealed rounds, three
 * voters): 90 queries per tick on the share view, which reported every
 * round to show one; 37 on the member page, whose fingerprint counted the
 * votes of every sealed round.
 *
 * Mutations that must turn this red: report every round on the share view
 * again; put a tally back into the member page's fingerprint.
 */

/**
 * A board of one, three members, and an announced election with six rounds,
 * each voted on by every member and sealed.
 *
 * @return array{0: EinundzwanzigPleb, 1: Election, 2: list<EinundzwanzigPleb>}
 */
function sealedEvening(int $rounds = 6): array
{
    [$board] = electionBoard(1);
    $election = Election::factory()->announced()->create();
    $members = [electionVoter(), electionVoter(), electionVoter()];
    $opened = [];

    for ($position = 1; $position <= $rounds; $position++) {
        $opened[] = openElectionRound(['position' => $position], 2, $election);
    }

    foreach ($opened as $round) {
        $candidateId = (int) $round->candidates()->first()->id;

        foreach ($members as $member) {
            castElectionBallot($round->fresh(), $member, [$candidateId => 'for']);
            // The ballot rate limit is per member; six rounds in a row are no abuse.
            Cache::flush();
        }
    }

    foreach ($opened as $round) {
        app(CloseRound::class)->handle($round->fresh());
    }

    return [$board, $election, $members];
}

/**
 * The queries one call of `$tick` runs.
 */
function queriesPerTick(Closure $tick): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $tick();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('reports only the shown round on each poll of the share view', function () {
    [$board, $election] = sealedEvening();
    NostrAuth::login($board->pubkey);
    $screen = Livewire::test('association.election.screen', ['election' => $election->id])
        ->assertSeeHtml('data-test="share-result"');

    $queries = queriesPerTick(fn (): Testable => $screen->call('$refresh'));

    // 17 measured after the change; the baseline was 90.
    expect($queries)->toBeLessThanOrEqual(20);
});
