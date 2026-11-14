<?php

use App\Enums\ElectionCountingRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../Feature/Services/Election/helpers.php';

/*
 * Two requests of the same member casting at the same moment.
 *
 * Needs two connections writing at once, which SQLite in memory cannot
 * give: two processes boot the application, wait at an advisory lock this
 * test holds, and are let go together once both are waiting. The test runs
 * without the wrapping transaction (group "commits") so that they see the
 * round and the member.
 *
 * Mutation that must turn this file red (patch in the plan folder):
 *  - B1-G2 `CastBallot` writes the mark with insertOrIgnore, so a second
 *    mark of the same member no longer stops the cast
 */

it('takes in one ballot when the same member casts twice at the same moment', function () {
    $voter = electionVoter();
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: 2);
    $candidate = $round->candidates()->orderBy('id')->value('id');
    $barrier = random_int(1, PHP_INT_MAX);

    DB::select('select pg_advisory_lock(?)', [$barrier]);

    $command = [PHP_BINARY, __DIR__.'/Support/cast-ballot-process.php', (string) $round->id, (string) $voter->id, (string) $candidate, (string) $barrier];
    $casts = [Process::timeout(60)->start($command), Process::timeout(60)->start($command)];

    $waiting = fn (): int => (int) DB::scalar(
        "select count(*) from pg_locks where locktype = 'advisory' and not granted and ((classid::bigint << 32) | objid::bigint) = ?",
        [$barrier],
    );

    $deadline = microtime(true) + 30;

    while ($waiting() < 2 && microtime(true) < $deadline && $casts[0]->running() && $casts[1]->running()) {
        usleep(20_000);
    }

    // Both are at the barrier — the precondition of the whole test.
    expect($waiting())->toBe(2);

    DB::select('select pg_advisory_unlock(?)', [$barrier]);

    $outcomes = array_map(function ($cast): string {
        $result = $cast->wait();

        expect($result->exitCode())->toBe(0, $result->errorOutput());

        return trim($result->output());
    }, $casts);

    sort($outcomes);

    expect($outcomes)->toBe(['AlreadyVoted', 'cast'])
        ->and(electionRowCounts($round))->toBe(['marks' => 1, 'choices' => 1, 'receipts' => 1]);
})->group('commits');
