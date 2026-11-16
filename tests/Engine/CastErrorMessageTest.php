<?php

use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionBallotWriteException;
use App\Services\Election\CastBallot;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Services/Election/helpers.php';

/*
 * A database error in the middle of a cast, on the engine that writes the
 * refused row into its message.
 *
 * PostgreSQL appends "DETAIL: Failing row contains (…)" to a check or
 * not-null violation and "Key (…)=(…)" to a key violation; measured on
 * postgres:18-alpine, 2026-11-14. For a vote that row is the member, the
 * pubkey and the choices. Masking the bindings does not reach that text.
 * SQLite says none of it, which is why this lives in the engine suite.
 *
 * The constraints are made up for the test: nothing in the schema refuses a
 * valid vote. They stand for whatever may one day — a migration.
 *
 * Mutation that must turn this file red (patch in the plan folder):
 *  - B1-G3 `CastBallot` lets the `QueryException` through
 */

it('names no vote value when the database refuses a write of the cast', function (array $statements) {
    $voter = electionVoter();
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: 3);
    $candidate = $round->candidates()->orderBy('id')->value('id');
    $event = signedElectionVote($round, $voter, [$candidate => 'for']);

    foreach ($statements as $statement) {
        DB::unprepared($statement);
    }

    try {
        app(CastBallot::class)->handle($round, $voter, $event);
    } catch (Throwable $exception) {
    }

    expect($exception ?? null)->toBeInstanceOf(ElectionBallotWriteException::class)
        ->and($exception->getMessage())->toMatch('/^The database refused to take in the vote \(SQLSTATE 23\d{3}\)\.$/')
        ->and($exception->getPrevious())->toBeNull()
        // The test's own transaction is still alive.
        ->and(electionVoteCount($round))->toBe(0);
})->with([
    'check violation on the choices' => [[
        "ALTER TABLE election_votes ADD CONSTRAINT engine_probe CHECK (choices::text NOT LIKE '%for%')",
    ]],
    'check violation on the pubkey' => [[
        "ALTER TABLE election_votes ADD CONSTRAINT engine_probe CHECK (pubkey = '')",
    ]],
]);
