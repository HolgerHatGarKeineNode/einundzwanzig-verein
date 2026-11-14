<?php

use App\Enums\ElectionCountingRule;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Feature/Services/Election/helpers.php';

/*
 * What a cast leaves behind on PostgreSQL that no column shows.
 *
 * A cast writes its mark, its ballot rows and its receipt in one
 * transaction. On PostgreSQL every row carries the id of the transaction
 * that wrote it (`xmin`), and rows inserted one after another sit one after
 * another in their table (`ctid`). Before the close both link a ballot to
 * the member who cast it; the rewrite at the close must cut both links.
 *
 * Each cast runs in a savepoint inside the test's transaction, and a
 * savepoint that writes gets a transaction id of its own — so the casts
 * carry distinct `xmin` values here as they do in production, where each is
 * committed on its own.
 *
 * Mutations that must turn this file red (patches in the plan folder):
 *  - B1-G1 `CloseRound` seals without rewriting ballot rows and receipts
 *  - B1-M2 the manifest lists the receipt hashes in table order, unsorted
 */

/**
 * An open most-votes round with `$voters` casts, each for a candidate of its
 * own. Returns the round, the voter ids in cast order and the candidate each
 * of them chose.
 *
 * @return array{0: ElectionRound, 1: list<int>, 2: array<int, int>}
 */
function tracedRound(int $voters = 12): array
{
    $members = collect(range(1, $voters))->map(fn () => electionVoter());
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: $voters);
    $candidates = $round->candidates()->orderBy('id')->pluck('id')->all();
    $candidateOf = [];

    foreach ($members->values() as $index => $member) {
        castElectionBallot($round, $member, [$candidates[$index] => 'for']);
        $candidateOf[$member->id] = $candidates[$index];
    }

    return [$round, $members->pluck('id')->all(), $candidateOf];
}

/**
 * `column` of every row of `$table` in the round, in physical order (ctid).
 *
 * @return list<mixed>
 */
function physicalOrder(string $table, ElectionRound $round, string $column): array
{
    return DB::table($table)
        ->where('election_round_id', $round->id)
        ->orderByRaw('ctid')
        ->pluck($column)
        ->all();
}

/**
 * The transaction ids that wrote the rows of `$table` in the round, keyed by
 * `$key`.
 *
 * @return array<array-key, string>
 */
function writers(string $table, ElectionRound $round, string $key): array
{
    return DB::table($table)
        ->where('election_round_id', $round->id)
        ->selectRaw("xmin::text as writer, {$key} as row_key")
        ->pluck('writer', 'row_key')
        ->all();
}

it('links a ballot to its mark by xmin and position before the close — the control', function () {
    [$round, $castOrder, $candidateOf] = tracedRound();

    $markWriters = writers('election_marks', $round, 'einundzwanzig_pleb_id');
    $choiceWriters = writers('election_ballot_choices', $round, 'election_candidate_id');

    // Every cast has its own transaction id, and its ballot row carries the same.
    expect(array_unique($markWriters))->toHaveCount(12);

    foreach ($candidateOf as $member => $candidate) {
        expect($choiceWriters[$candidate])->toBe($markWriters[$member]);
    }

    expect(physicalOrder('election_marks', $round, 'einundzwanzig_pleb_id'))->toBe($castOrder)
        ->and(physicalOrder('election_ballot_choices', $round, 'election_candidate_id'))->toBe(array_values($candidateOf));
});

it('leaves no ballot row or receipt with the xmin of a mark after the close', function () {
    [$round] = tracedRound();

    app(CloseRound::class)->handle($round);

    $markWriters = array_values(writers('election_marks', $round, 'einundzwanzig_pleb_id'));
    $choiceWriters = array_values(writers('election_ballot_choices', $round, 'id'));
    $receiptWriters = array_values(writers('election_receipts', $round, 'hash'));

    expect($choiceWriters)->toHaveCount(12)
        ->and($receiptWriters)->toHaveCount(12)
        ->and(array_intersect($choiceWriters, $markWriters))->toBe([])
        ->and(array_intersect($receiptWriters, $markWriters))->toBe([])
        // All of them were written by the close, and by nothing else.
        ->and(array_unique([...$choiceWriters, ...$receiptWriters]))->toHaveCount(1);
});

it('leaves the ballot rows and receipts out of cast order after the close', function () {
    [$round, , $candidateOf] = tracedRound();
    $receiptsInCastOrder = physicalOrder('election_receipts', $round, 'hash');

    app(CloseRound::class)->handle($round);

    /*
     * The rewrite shuffles with the CSPRNG; that it keeps the cast order of
     * 12 rows by chance has a probability of 1 in 12! (about 2e-9).
     */
    expect(physicalOrder('election_ballot_choices', $round, 'election_candidate_id'))->not->toBe(array_values($candidateOf))
        ->and(physicalOrder('election_receipts', $round, 'hash'))->not->toBe($receiptsInCastOrder)
        ->and(physicalOrder('election_ballot_choices', $round, 'election_candidate_id'))->toEqualCanonicalizing(array_values($candidateOf))
        ->and(physicalOrder('election_receipts', $round, 'hash'))->toEqualCanonicalizing($receiptsInCastOrder);
});

it('lists the receipt hashes of the manifest sorted, not in the order the rewrite left them', function () {
    [$round] = tracedRound();

    /*
     * Which order a query without ORDER BY returns is the planner's choice.
     * On a test-sized table it reads the unique index (round, hash) and
     * hands the receipts out sorted anyway; on a table of real size it may
     * scan the heap. Ruled out here, for the rest of the test's transaction.
     */
    DB::statement('SET LOCAL enable_indexscan = off');
    DB::statement('SET LOCAL enable_indexonlyscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');

    app(CloseRound::class)->handle($round);

    $hashes = json_decode(ElectionManifest::query()->where('election_round_id', $round->id)->value('body'), true)['receipt_hashes'];
    $sorted = $hashes;
    sort($sorted, SORT_STRING);

    /*
     * SQLite hands the receipts out through the unique index, sorted either
     * way; only here does a manifest that skips the sort show it.
     */
    expect($hashes)->toHaveCount(12)
        ->and($hashes)->toBe($sorted)
        ->and(physicalOrder('election_receipts', $round, 'hash'))->not->toBe($sorted);
});
