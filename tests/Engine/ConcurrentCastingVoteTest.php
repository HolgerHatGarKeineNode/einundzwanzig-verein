<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCountingRule;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCastingVote;
use App\Services\Election\CloseRound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/../Feature/Services/Election/helpers.php';

/*
 * Two election officers entering the chair's casting vote on the same tie at
 * the same moment, each for a different candidate.
 *
 * Built like ConcurrentCastTest: two processes boot the application, wait
 * at an advisory lock this test holds, and are let go together once both
 * are waiting. The test runs without the wrapping transaction (group
 * "commits") so that they see the round and the board.
 *
 * Mutation that must turn this file red: the lockForUpdate in
 * RecordCastingVote removed together with the unique index on
 * election_casting_votes.election_round_id.
 */

it('records one casting vote and one audit line when two arrive at the same moment', function () {
    [$board] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::SingleMajority, 'seats' => 1], candidates: 2);
    [$first, $second] = $round->candidates()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    castElectionBallot($round, $voters[0], [$first => 'for']);
    castElectionBallot($round, $voters[1], [$second => 'for']);
    $round = app(CloseRound::class)->handle($round);
    $barrier = random_int(1, PHP_INT_MAX);

    $command = fn (int $candidate): array => [
        PHP_BINARY, __DIR__.'/Support/record-casting-vote-process.php', (string) $round->id, (string) $board->id,
        (string) $candidate, (string) $barrier,
    ];

    DB::select('select pg_advisory_lock(?)', [$barrier]);

    $records = [Process::timeout(60)->start($command($first)), Process::timeout(60)->start($command($second))];

    $waiting = fn (): int => (int) DB::scalar(
        "select count(*) from pg_locks where locktype = 'advisory' and not granted and ((classid::bigint << 32) | objid::bigint) = ?",
        [$barrier],
    );

    $deadline = microtime(true) + 30;

    while ($waiting() < 2 && microtime(true) < $deadline && $records[0]->running() && $records[1]->running()) {
        usleep(20_000);
    }

    // Both are at the barrier — the precondition of the whole test.
    expect($waiting())->toBe(2);

    DB::select('select pg_advisory_unlock(?)', [$barrier]);

    $outcomes = array_map(function ($record): string {
        $result = $record->wait();

        expect($result->exitCode())->toBe(0, $result->errorOutput());

        return trim($result->output());
    }, $records);

    sort($outcomes);

    expect($outcomes)->toBe(['recorded', 'refused'])
        ->and(ElectionCastingVote::query()->where('election_round_id', $round->id)->count())->toBe(1)
        ->and(ElectionAuditEntry::query()->where('election_round_id', $round->id)->where('action', ElectionAuditAction::CastingVoteRecorded->value)->count())->toBe(1);
})->group('commits');
