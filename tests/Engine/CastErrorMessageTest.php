<?php

use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionBallotWriteException;
use App\Models\ElectionReceipt;
use App\Services\Election\CastBallot;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Services/Election/helpers.php';

/*
 * A database error in the middle of a cast, on the engine that writes the
 * refused row into its message.
 *
 * PostgreSQL appends "DETAIL: Failing row contains (…)" to a check or
 * not-null violation and "Key (…)=(…)" to a key violation; measured on
 * postgres:18-alpine, 2026-11-14, the check case read "Failing row contains
 * (<ballot id>, <round>, <candidate>, for)" and the receipt case carried the
 * receipt hash. Masking the bindings does not reach that text. SQLite says
 * none of it, which is why this lives in the engine suite.
 *
 * The constraints and triggers are made up for the test: nothing in the
 * schema refuses a valid ballot row. They stand for whatever may one day —
 * a migration, a deleted candidate.
 *
 * Mutation that must turn this file red (patch in the plan folder):
 *  - B1-G3 `CastBallot` lets the `QueryException` through
 */

it('names no ballot value when the database refuses a write of the cast', function (array $statements) {
    $voter = electionVoter();
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: 3);
    $candidate = $round->candidates()->orderBy('id')->value('id');
    $code = electionReceiptCode();

    foreach ($statements as $statement) {
        DB::unprepared($statement);
    }

    try {
        app(CastBallot::class)->handle($round, $voter, [$candidate => 'for'], $code);
    } catch (Throwable $exception) {
    }

    expect($exception ?? null)->toBeInstanceOf(ElectionBallotWriteException::class)
        ->and($exception->getMessage())->toMatch('/^The database refused to take in the ballot \(SQLSTATE 23\d{3}\)\.$/')
        ->and($exception->getMessage())->not->toContain(ElectionReceipt::hashFor($round->id, $code))
        ->and($exception->getPrevious())->toBeNull()
        // All or nothing, and the test's own transaction is still alive.
        ->and(electionRowCounts($round))->toBe(['marks' => 0, 'choices' => 0, 'receipts' => 0]);
})->with([
    'check violation on a ballot row' => [[
        "ALTER TABLE election_ballot_choices ADD CONSTRAINT engine_probe CHECK (choice <> 'for')",
    ]],
    'check violation on a receipt' => [[
        "ALTER TABLE election_receipts ADD CONSTRAINT engine_probe CHECK (hash = '')",
    ]],
    'the chosen candidate is gone' => [[
        'CREATE FUNCTION engine_probe() RETURNS trigger AS $$ BEGIN DELETE FROM election_candidates WHERE id = NEW.election_candidate_id; RETURN NEW; END $$ LANGUAGE plpgsql',
        'CREATE TRIGGER engine_probe BEFORE INSERT ON election_ballot_choices FOR EACH ROW EXECUTE FUNCTION engine_probe()',
    ]],
]);
