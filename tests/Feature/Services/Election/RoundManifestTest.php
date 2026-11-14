<?php

use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionManifest;
use App\Models\ElectionReceipt;
use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use App\Services\Election\OpenRound;
use App\Services\Election\RoundManifest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
 * The manifest written at the close, and `election:verify`.
 *
 * Mutations that must turn this file red (patches in the plan folder):
 *  - M6a   `ballots_cast` counted from the marks instead of the receipts
 *  - B1-M1 `CloseRound` seals without writing a manifest
 *  - (B1-M2, unsorted receipt hashes, stays green on SQLite, which reads them
 *    through the unique index; tests/Engine/BallotTraceTest.php catches it)
 *  - B1-M3 `election:verify` compares the stored text with itself only
 *  - B1-M4 `election:verify` does not check the stored text against its hash
 */

/**
 * A sealed most-votes round with three candidates and four ballots.
 *
 * @return array{0: ElectionRound, 1: list<string>, 2: list<int>}
 */
function manifestRound(): array
{
    $voters = collect(range(1, 5))->map(fn () => electionVoter());
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 3);
    $ids = $round->candidates()->orderBy('id')->pluck('id')->all();

    $codes = [
        castElectionBallot($round, $voters[0], [$ids[0] => 'for', $ids[1] => 'for']),
        castElectionBallot($round, $voters[1], [$ids[0] => 'for']),
        castElectionBallot($round, $voters[2], [$ids[1] => 'for', $ids[2] => 'for']),
        castElectionBallot($round, $voters[3]),
    ];

    return [app(CloseRound::class)->handle($round), $codes, $ids];
}

/**
 * @return array{0: int, 1: string}
 */
function verifyRound(ElectionRound|int $round): array
{
    $status = Artisan::call('election:verify', ['round' => $round instanceof ElectionRound ? $round->getKey() : $round]);

    return [$status, Artisan::output()];
}

it('writes the manifest of a round when it is sealed, with every field written out', function () {
    $this->travelTo(Carbon::parse('2027-03-20 15:30:00'));

    [$round, $codes, [$a, $b, $c]] = manifestRound();

    $manifest = ElectionManifest::query()->where('election_round_id', $round->id)->sole();
    $receipts = collect($codes)->map(fn (string $code): string => ElectionReceipt::hashFor($round->id, $code))->sort()->values()->all();

    expect($manifest->hash)->toBe(hash('sha256', $manifest->body))
        ->and(json_decode($manifest->body, true))->toBe([
            'version' => 1,
            'election' => $round->election_event_id,
            'round' => $round->id,
            'counting_rule' => 'most_votes',
            'seats' => 2,
            'candidates' => [$a, $b, $c],
            'counts' => [
                (string) $a => ['for' => 2, 'yes' => 0, 'no' => 0],
                (string) $b => ['for' => 2, 'yes' => 0, 'no' => 0],
                (string) $c => ['for' => 1, 'yes' => 0, 'no' => 0],
            ],
            'blank' => 1,
            'outcome' => 'complete',
            'elected' => [$a, $b],
            'tied' => [],
            'tied_seats' => 0,
            'roll_size' => 5,
            'marks' => 4,
            'ballots_cast' => 4,
            'receipt_list_hash' => hash('sha256', implode("\n", $receipts)),
            'receipt_hashes' => $receipts,
            'closed_at' => '2027-03-20T15:30:00Z',
            'audit_head_hash' => null,
        ]);
});

it('carries no member and no ballot id in the manifest', function () {
    [$round] = manifestRound();
    $body = ElectionManifest::query()->where('election_round_id', $round->id)->value('body');

    $identifiers = EinundzwanzigPleb::query()->get()
        ->flatMap(fn (EinundzwanzigPleb $pleb): array => [$pleb->pubkey, $pleb->npub])
        ->merge(DB::table('election_ballot_choices')->pluck('id'))
        ->filter(fn (?string $identifier): bool => strlen((string) $identifier) >= 16);

    expect($identifiers)->not->toBeEmpty();

    foreach ($identifiers as $identifier) {
        expect($body)->not->toContain($identifier);
    }
});

it('counts the ballots cast from the receipts and the marks on their own', function () {
    [$round] = manifestRound();
    $paperVoter = electionVoter();

    // A mark without a ballot — what a vote on paper will leave behind.
    DB::table('election_marks')->insert(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $paperVoter->id]);

    $body = json_decode(app(RoundManifest::class)->body($round), true);

    expect($body['marks'])->toBe(5)
        ->and($body['ballots_cast'])->toBe(4);
});

it('writes one manifest however often a round is sealed', function () {
    [$round] = manifestRound();
    $hash = ElectionManifest::query()->where('election_round_id', $round->id)->value('hash');

    app(CloseRound::class)->handle($round);

    expect(ElectionManifest::query()->where('election_round_id', $round->id)->pluck('hash')->all())->toBe([$hash]);
});

it('leaves a round unsealed and its ballots untouched when the manifest cannot be written', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    castElectionBallot($round, $voter);
    $idBefore = DB::table('election_ballot_choices')->where('election_round_id', $round->id)->value('id');

    DB::statement("CREATE TRIGGER refuse_manifests BEFORE INSERT ON election_manifests BEGIN SELECT RAISE(ABORT, 'manifest refused'); END");

    expect(fn () => app(CloseRound::class)->handle($round))->toThrow(Exception::class, 'manifest refused')
        ->and($round->fresh()->closed_at)->toBeNull()
        ->and(DB::table('election_ballot_choices')->where('election_round_id', $round->id)->value('id'))->toBe($idBefore);
});

it('refuses to change or delete a manifest', function () {
    [$round] = manifestRound();
    $manifest = ElectionManifest::query()->where('election_round_id', $round->id)->sole();

    expect(fn () => $manifest->update(['body' => '{}']))->toThrow(ElectionRoundStateException::class)
        ->and(fn () => $manifest->delete())->toThrow(ElectionRoundStateException::class)
        ->and(ElectionManifest::query()->where('election_round_id', $round->id)->value('hash'))->toBe($manifest->hash);
});

it('verifies an untouched round with exit 0', function () {
    [$round] = manifestRound();
    $hash = ElectionManifest::query()->where('election_round_id', $round->id)->value('hash');

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(0)
        ->and($output)->toContain('OK round '.$round->id.' manifest '.$hash);
});

it('fails verification once a ballot row is altered after the close', function (Closure $tamper, string $field) {
    [$round, , $ids] = manifestRound();

    $tamper($round, $ids);

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(1)
        ->and($output)->toContain('MISMATCH: the ballot tables no longer give the manifest written at the close.')
        ->and($output)->toContain('Differing fields: ')
        ->and($output)->toContain($field);
})->with([
    'a vote moved to another candidate' => [function (ElectionRound $round, array $ids): void {
        $id = DB::table('election_ballot_choices')->where('election_round_id', $round->id)->where('election_candidate_id', $ids[2])->value('id');
        DB::table('election_ballot_choices')->where('id', $id)->update(['election_candidate_id' => $ids[0]]);
    }, 'counts'],
    'a blank ballot turned into a vote' => [function (ElectionRound $round, array $ids): void {
        DB::table('election_ballot_choices')->where('election_round_id', $round->id)->where('choice', 'blank')
            ->update(['choice' => 'for', 'election_candidate_id' => $ids[2]]);
    }, 'blank'],
    'a ballot row deleted' => [function (ElectionRound $round, array $ids): void {
        $id = DB::table('election_ballot_choices')->where('election_round_id', $round->id)->where('election_candidate_id', $ids[2])->value('id');
        DB::table('election_ballot_choices')->where('id', $id)->delete();
    }, 'counts'],
    'a ballot row added' => [function (ElectionRound $round, array $ids): void {
        DB::table('election_ballot_choices')->insert(['id' => bin2hex(random_bytes(16)), 'election_round_id' => $round->id, 'election_candidate_id' => $ids[2], 'choice' => 'for']);
    }, 'counts'],
    'a receipt swapped' => [function (ElectionRound $round): void {
        DB::table('election_receipts')->where('election_round_id', $round->id)->limit(1)->update(['hash' => str_repeat('0', 64)]);
    }, 'receipt_hashes'],
    'a roll entry added' => [function (ElectionRound $round): void {
        DB::table('election_roll_entries')->insert([
            'election_round_id' => $round->id,
            'einundzwanzig_pleb_id' => electionVoter()->id,
            'reason' => 'active_fee_paid',
            'source' => 'opening',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }, 'roll_size'],
]);

it('names the differing fields and none of their values', function () {
    [$round, , $ids] = manifestRound();
    DB::table('election_ballot_choices')->where('election_round_id', $round->id)->where('election_candidate_id', $ids[2])->update(['election_candidate_id' => $ids[0]]);

    [, $output] = verifyRound($round);

    expect($output)->toContain('Differing fields: counts')
        ->and($output)->not->toMatch('/\b'.$ids[0].'\b/');
});

it('fails verification when the stored manifest is altered', function () {
    [$round] = manifestRound();

    DB::table('election_manifests')->where('election_round_id', $round->id)
        ->update(['body' => DB::raw("replace(body, '\"seats\":2', '\"seats\":3')")]);

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(1)
        ->and($output)->toContain('MISMATCH: the stored manifest text no longer has the stored hash.');
});

it('fails verification when the stored hash is replaced', function () {
    [$round] = manifestRound();

    DB::table('election_manifests')->where('election_round_id', $round->id)->update(['hash' => str_repeat('a', 64)]);

    expect(verifyRound($round)[0])->toBe(1);
});

it('has nothing to verify for an unknown, open or annulled round', function () {
    $open = openElectionRound();
    [$annulled] = manifestRound();
    $annulled->annulled_at = now();
    $annulled->save();

    expect(verifyRound(999999))->toBe([2, "No such round.\n"])
        ->and(verifyRound($open))->toBe([2, "This round has no manifest. Only a sealed round has one.\n"])
        ->and(verifyRound($annulled)[0])->toBe(2);
});

it('builds the same text from the same tables', function () {
    [$round] = manifestRound();

    expect(app(RoundManifest::class)->body($round))
        ->toBe(ElectionManifest::query()->where('election_round_id', $round->id)->value('body'))
        ->and(fn () => app(RoundManifest::class)->body(app(OpenRound::class)->handle(draftElectionRound())))
        ->toThrow(ElectionRoundStateException::class);
});
