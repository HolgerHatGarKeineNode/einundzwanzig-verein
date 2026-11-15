<?php

use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\OpenRound;
use App\Services\Election\RoundManifest;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
 * The manifest written at the close, and `election:verify`.
 *
 * Mutations that must turn this file red (patches in the plan folder):
 *  - B1-M1 `CloseRound` seals without writing a manifest
 *  - B1-M3 `election:verify` compares the stored text with itself only
 *  - B1-M4 `election:verify` does not check the stored text against its hash
 *  - P2C-verify-signature  `election:verify` skips the signature re-check
 */

/**
 * A sealed most-votes round with three candidates and four votes. Returns
 * the round, the signed events and the candidate ids.
 *
 * @return array{0: ElectionRound, 1: list<array<string, mixed>>, 2: list<int>}
 */
function manifestRound(): array
{
    $voters = collect(range(1, 5))->map(fn () => electionVoter());
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 3);
    $ids = $round->candidates()->orderBy('id')->pluck('id')->all();

    $events = [
        castElectionBallot($round, $voters[0], [$ids[0] => 'for', $ids[1] => 'for']),
        castElectionBallot($round, $voters[1], [$ids[0] => 'for']),
        castElectionBallot($round, $voters[2], [$ids[1] => 'for', $ids[2] => 'for']),
        castElectionBallot($round, $voters[3]),
    ];

    return [app(CloseRound::class)->handle($round), $events, $ids];
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

    [$round, $events, [$a, $b, $c]] = manifestRound();

    $manifest = ElectionManifest::query()->where('election_round_id', $round->id)->sole();
    $eventIds = collect($events)->pluck('id')->sort(SORT_STRING)->values()->all();

    expect($manifest->hash)->toBe(hash('sha256', $manifest->body))
        ->and(json_decode($manifest->body, true))->toBe([
            'version' => 2,
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
            'votes_cast' => 4,
            'vote_event_ids_hash' => hash('sha256', implode("\n", $eventIds)),
            'closed_at' => '2027-03-20T15:30:00Z',
            // Sealed without a line in the audit log: its head is the genesis hash.
            'audit_head_hash' => ElectionAuditLog::GENESIS,
        ]);
});

it('ties the manifest to the head of the audit log at the seal, and election:verify still agrees', function () {
    [$board] = electionBoard(1);
    $voter = electionVoter();
    $round = app(ElectionStewardship::class)->open(draftElectionRound(), $board);
    castElectionBallot($round, $voter, [$round->candidates()->orderBy('id')->value('id') => 'for']);

    $headBeforeSeal = app(ElectionAuditLog::class)->headHash($round->election);
    app(ElectionStewardship::class)->closeEarly($round, $board, 'Alle haben abgestimmt');

    $lines = ElectionAuditEntry::query()->where('election_event_id', $round->election_event_id)->orderBy('sequence')->get();
    $stored = json_decode(ElectionManifest::query()->where('election_round_id', $round->id)->value('body'), true);

    expect($lines->pluck('action')->map->value->all())->toBe(['round_opened', 'round_closed_early'])
        ->and($stored['audit_head_hash'])->toBe($headBeforeSeal)
        ->and($stored['audit_head_hash'])->toBe($lines[0]->hash)
        ->and($stored['audit_head_hash'])->not->toBe(ElectionAuditLog::GENESIS)
        ->and(verifyRound($round)[0])->toBe(0);
});

it('carries no member and no single vote in the manifest', function () {
    [$round] = manifestRound();
    $body = ElectionManifest::query()->where('election_round_id', $round->id)->value('body');

    $identifiers = EinundzwanzigPleb::query()->get()
        ->flatMap(fn (EinundzwanzigPleb $pleb): array => [$pleb->pubkey, $pleb->npub])
        ->merge(DB::table('election_votes')->pluck('event_id'))
        ->filter(fn (?string $identifier): bool => strlen((string) $identifier) >= 16);

    expect($identifiers)->not->toBeEmpty();

    foreach ($identifiers as $identifier) {
        expect($body)->not->toContain($identifier);
    }
});

it('writes one manifest however often a round is sealed', function () {
    [$round] = manifestRound();
    $hash = ElectionManifest::query()->where('election_round_id', $round->id)->value('hash');

    app(CloseRound::class)->handle($round);

    expect(ElectionManifest::query()->where('election_round_id', $round->id)->pluck('hash')->all())->toBe([$hash]);
});

it('leaves a round unsealed and its votes untouched when the manifest cannot be written', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    castElectionBallot($round, $voter);
    $votesBefore = DB::table('election_votes')->where('election_round_id', $round->id)->get()->all();

    DB::statement("CREATE TRIGGER refuse_manifests BEFORE INSERT ON election_manifests BEGIN SELECT RAISE(ABORT, 'manifest refused'); END");

    expect(fn () => app(CloseRound::class)->handle($round))->toThrow(Exception::class, 'manifest refused')
        ->and($round->fresh()->closed_at)->toBeNull()
        ->and(DB::table('election_votes')->where('election_round_id', $round->id)->get()->all())->toEqual($votesBefore);
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

it('fails verification once a vote is altered after the close', function (Closure $tamper, string $field) {
    [$round, , $ids] = manifestRound();

    $tamper($round, $ids);

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(1)
        ->and($output)->toContain('MISMATCH: the vote table no longer gives the manifest written at the close.')
        ->and($output)->toContain('Differing fields: ')
        ->and($output)->toContain($field);
})->with([
    'a vote moved to another candidate' => [function (ElectionRound $round, array $ids): void {
        DB::table('election_votes')->where('election_round_id', $round->id)->where('choices', json_encode([(string) $ids[1] => 'for', (string) $ids[2] => 'for']))
            ->update(['choices' => json_encode([(string) $ids[0] => 'for', (string) $ids[1] => 'for'])]);
    }, 'counts'],
    'a blank vote turned into a vote' => [function (ElectionRound $round, array $ids): void {
        DB::table('election_votes')->where('election_round_id', $round->id)->where('choices', '{}')
            ->update(['choices' => json_encode([(string) $ids[2] => 'for'])]);
    }, 'blank'],
    'a vote deleted' => [function (ElectionRound $round, array $ids): void {
        DB::table('election_votes')->where('election_round_id', $round->id)->where('choices', json_encode([(string) $ids[0] => 'for']))->delete();
    }, 'votes_cast'],
    'a vote event id swapped' => [function (ElectionRound $round): void {
        DB::table('election_votes')->where('election_round_id', $round->id)->limit(1)->update(['event_id' => str_repeat('0', 64)]);
    }, 'vote_event_ids_hash'],
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
    DB::table('election_votes')->where('election_round_id', $round->id)->where('choices', json_encode([(string) $ids[0] => 'for']))
        ->update(['choices' => json_encode([(string) $ids[2] => 'for'])]);

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

    expect(app(RoundManifest::class)->body($round, ElectionAuditLog::GENESIS))
        ->toBe(ElectionManifest::query()->where('election_round_id', $round->id)->value('body'))
        ->and(fn () => app(RoundManifest::class)->body(app(OpenRound::class)->handle(draftElectionRound())))
        ->toThrow(ElectionRoundStateException::class);
});

/**
 * The stored vote of `$event`, updated past the application with `$change`
 * applied to its decoded event.
 */
function tamperStoredVoteEvent(array $event, Closure $change): int
{
    $vote = DB::table('election_votes')->where('event_id', $event['id'])->sole();
    DB::table('election_votes')->where('id', $vote->id)->update(['event' => json_encode($change(json_decode($vote->event, true)))]);

    return $vote->id;
}

it('re-verifies every signed vote, recounts and finds nothing in an untouched round', function () {
    [$round, $events] = manifestRound();

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(0)
        ->and($output)->not->toContain('FAIL')
        ->and($output)->not->toContain('MISMATCH')
        ->and(DB::table('election_votes')->where('election_round_id', $round->id)->count())->toBe(count($events));
});

it('names the vote whose stored choices no longer match its signed event, and no choice', function () {
    [$round, $events, $ids] = manifestRound();
    $vote = DB::table('election_votes')->where('event_id', $events[1]['id'])->sole();
    DB::table('election_votes')->where('id', $vote->id)->update(['choices' => json_encode([(string) $ids[2] => 'for'])]);

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(1)
        ->and($output)->toContain('FAIL vote '.$vote->id.' event '.$events[1]['id'].': choices_differ')
        ->and($output)->not->toContain('"for"')
        ->and($output)->not->toMatch('/\bfor\b/');
});

it('names the vote whose signed event was altered after the close', function () {
    [$round, $events] = manifestRound();
    $voteId = tamperStoredVoteEvent($events[1], fn (array $event): array => [...$event, 'created_at' => $event['created_at'] + 1]);

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(1)
        ->and($output)->toContain('FAIL vote '.$voteId.' event '.$events[1]['id'].": invalid_signature\n")
        ->and($output)->toContain('MISMATCH: the recount from the signed events differs from the manifest in: counts')
        ->and($output)->toContain('votes_cast');
});

it('names every check a forged vote fails', function () {
    [$round, $events, $ids] = manifestRound();
    $voteId = tamperStoredVoteEvent($events[1], function (array $event) use ($ids): array {
        $event['tags'][2][1] = (string) $ids[2];

        return $event;
    });

    expect(verifyRound($round)[1])->toContain('FAIL vote '.$voteId.' event '.$events[1]['id'].': invalid_signature, choices_differ');
});

it('names a vote replayed from another round', function () {
    [$round, $events] = manifestRound();
    $voteId = tamperStoredVoteEvent($events[3], function (array $event): array {
        $event['tags'][1][1] = (string) ($event['tags'][1][1] + 1);

        return $event;
    });

    expect(verifyRound($round)[1])->toContain('FAIL vote '.$voteId.' event '.$events[3]['id'].': invalid_signature, wrong_round');
});

it('names a vote whose member no longer has the signing key', function () {
    [$round, $events] = manifestRound();
    $vote = DB::table('election_votes')->where('event_id', $events[0]['id'])->sole();
    DB::table('einundzwanzig_plebs')->where('id', $vote->einundzwanzig_pleb_id)->update(['pubkey' => str_repeat('c', 64)]);

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(1)
        ->and($output)->toContain('FAIL vote '.$vote->id.' event '.$events[0]['id'].': foreign_signer');
});

/*
 * Jury run 1, A: `election:verify` copied the anchored audit head into the
 * rebuilt manifest and never looked at the audit log itself. A line written
 * before the seal could be edited, or deleted with the rest re-hashed, and
 * the command still printed OK.
 */

/**
 * A round opened, voted in and closed early through the stewardship, so the
 * audit log holds `round_opened` (the anchor) and `round_closed_early`.
 *
 * @return array{0: ElectionRound, 1: string}
 */
function stewardedManifestRound(): array
{
    [$board] = electionBoard(1);
    $voter = electionVoter();
    $round = app(ElectionStewardship::class)->open(draftElectionRound(), $board);
    castElectionBallot($round, $voter, [$round->candidates()->orderBy('id')->value('id') => 'for']);
    app(ElectionStewardship::class)->closeEarly($round, $board, 'Alle haben abgestimmt');

    $anchor = json_decode(ElectionManifest::query()->where('election_round_id', $round->id)->value('body'), true)['audit_head_hash'];

    return [$round->fresh(), $anchor];
}

it('fails verification when an audit line before the seal is edited in place', function () {
    [$round, $anchor] = stewardedManifestRound();
    $opened = ElectionAuditEntry::query()->where('election_event_id', $round->election_event_id)->where('sequence', 1)->sole();
    DB::table('election_audit_entries')->where('id', $opened->id)->update(['details' => json_encode(['reason' => 'nachträglich'])]);

    [$status, $output] = verifyRound($round);

    expect($opened->hash)->toBe($anchor)
        ->and(app(ElectionAuditLog::class)->verify($round->election))->toBeFalse()
        ->and($status)->toBe(1)
        ->and($output)->toContain('MISMATCH: the audit log of this election no longer holds its hash chain.')
        ->and($output)->not->toContain('OK round');
});

it('fails verification when an audit line before the seal is deleted and the rest re-hashed', function () {
    [$round, $anchor] = stewardedManifestRound();
    $log = app(ElectionAuditLog::class);
    $attributesOf = (new ReflectionMethod($log, 'attributesOf'))->getClosure($log);
    $hashOf = (new ReflectionMethod($log, 'hashOf'))->getClosure($log);

    DB::table('election_audit_entries')->where('election_event_id', $round->election_event_id)->where('sequence', 1)->delete();
    $previous = ElectionAuditLog::GENESIS;
    foreach (ElectionAuditEntry::query()->where('election_event_id', $round->election_event_id)->orderBy('sequence')->get() as $entry) {
        DB::table('election_audit_entries')->where('id', $entry->id)->update(['sequence' => $entry->sequence - 1, 'previous_hash' => $previous]);
        $rewritten = $entry->fresh();
        $previous = $hashOf($attributesOf($rewritten));
        DB::table('election_audit_entries')->where('id', $entry->id)->update(['hash' => $previous]);
    }

    [$status, $output] = verifyRound($round);

    expect($log->verify($round->election))->toBeTrue()
        ->and(ElectionAuditEntry::query()->where('hash', $anchor)->exists())->toBeFalse()
        ->and($status)->toBe(1)
        ->and($output)->toContain('MISMATCH: the audit log head the manifest anchored at the seal is no longer a line of the audit log.')
        ->and($output)->not->toContain('OK round');
});

/*
 * Jury run 1, C: erasing one's data tombstones the member pubkey. The
 * verifier compared it with the vote's pubkey, reported `foreign_signer`,
 * dropped the vote from the recount and the round turned red for good.
 */
it('verifies a sealed round after a voter erased their data, against the key that signed the vote', function () {
    [$round, $events] = manifestRound();
    $vote = DB::table('election_votes')->where('event_id', $events[0]['id'])->sole();
    $voter = EinundzwanzigPleb::query()->findOrFail($vote->einundzwanzig_pleb_id);

    app(MembershipService::class)->erasePersonalData($voter);

    [$status, $output] = verifyRound($round);

    expect($voter->fresh()->isErased())->toBeTrue()
        ->and(DB::table('election_votes')->where('id', $vote->id)->value('pubkey'))->toBe($events[0]['pubkey'])
        ->and($status)->toBe(0)
        ->and($output)->not->toContain('FAIL')
        ->and($output)->not->toContain('MISMATCH')
        ->and($output)->toContain('Note: 1 vote(s) by members who erased their data, checked against the key that signed them.');
});

it('still names a forged vote of a voter who erased their data', function () {
    [$round, $events] = manifestRound();
    $vote = DB::table('election_votes')->where('event_id', $events[0]['id'])->sole();
    app(MembershipService::class)->erasePersonalData(EinundzwanzigPleb::query()->findOrFail($vote->einundzwanzig_pleb_id));
    DB::table('election_votes')->where('id', $vote->id)->update(['pubkey' => str_repeat('c', 64)]);

    [$status, $output] = verifyRound($round);

    expect($status)->toBe(1)
        ->and($output)->toContain('FAIL vote '.$vote->id.' event '.$events[0]['id'].': foreign_signer');
});
