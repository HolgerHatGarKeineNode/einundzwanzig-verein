<?php

use App\Console\Commands\Nostr\FetchEvents;
use App\Console\Commands\Nostr\SyncProfiles;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Artisan;
use swentel\nostr\Key\Key;

/*
 * TWO SCHEDULED COMMANDS CARRY MEMBER KEYS OUT OF THE HOUSE, and an erased
 * record used to travel with them. `DELETE /api/v1/membership/me` anonymises
 * instead of deleting, so the row survives — and a filter that sits only on the
 * public HTTP list leaves every other exit open.
 */

/**
 * Records what the command actually hands to the relay layer instead of
 * connecting to anything.
 *
 * Observing the REAL argument of `fetchProfile()` rather than re-running the
 * command's query in the test: a test that rebuilds the query proves the test's
 * query right, not the command's.
 */
class SpyingSyncProfiles extends SyncProfiles
{
    /** @var array<int, string> */
    public array $sentToRelays = [];

    public function fetchProfile($npubs)
    {
        $this->sentToRelays = (array) $npubs;
    }
}

/**
 * Exposes the authors list without opening the subscription — `handle()` talks
 * to three real relays, which a test must never do.
 */
class ProbingFetchEvents extends FetchEvents
{
    /**
     * @return array<int, string>
     */
    public function probeSubjectPubkeys(): array
    {
        return $this->subjectPubkeys();
    }
}

/**
 * A member who has asked to be erased, and one who has not.
 *
 * @return array{erased: EinundzwanzigPleb, kept: EinundzwanzigPleb}
 */
function erasedAndKeptMember(): array
{
    $erasedPubkey = (new Key)->getPublicKey((new Key)->generatePrivateKey());
    $keptPubkey = (new Key)->getPublicKey((new Key)->generatePrivateKey());

    $erased = EinundzwanzigPleb::factory()->create([
        'pubkey' => $erasedPubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($erasedPubkey),
    ]);

    $kept = EinundzwanzigPleb::factory()->create([
        'pubkey' => $keptPubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($keptPubkey),
    ]);

    // Both had a cached profile; the erasure removes one of them, which is
    // precisely what pulls that row into `sync:profiles`'s selection.
    Profile::create(['pubkey' => $erasedPubkey, 'name' => 'erased-name']);
    Profile::create(['pubkey' => $keptPubkey, 'name' => 'kept-name']);

    app(MembershipService::class)->erasePersonalData($erased);

    return ['erased' => $erased->fresh(), 'kept' => $kept];
}

it('never sends an erased member to a profile relay', function () {
    /*
     * THE LEAK THAT WOULD HAVE RUN FOREVER. The erasure deletes the cached
     * kind-0 profile, so `whereDoesntHave('profile')` — the selection this
     * command runs on without --all — picks the anonymised row up on EVERY
     * run from then on and ships its tombstone npub to a relay. It does not
     * heal either: no profile will ever come back for a key that is not one.
     */
    $members = erasedAndKeptMember();

    $spy = new SpyingSyncProfiles;
    Artisan::registerCommand($spy);
    Artisan::call('sync:profiles');

    expect($spy->sentToRelays)->not->toContain($members['erased']->npub)
        ->and(collect($spy->sentToRelays)->filter(
            fn (string $npub): bool => str_starts_with($npub, EinundzwanzigPleb::TOMBSTONE_PREFIX)
        )->all())->toBe([]);
});

it('still syncs a member who has not been erased', function () {
    // The discriminating half: a command that sent nothing at all would
    // satisfy the test above and quietly stop syncing anybody.
    $members = erasedAndKeptMember();

    Profile::query()->where('pubkey', $members['kept']->pubkey)->delete();

    $spy = new SpyingSyncProfiles;
    Artisan::registerCommand($spy);
    Artisan::call('sync:profiles');

    expect($spy->sentToRelays)->toContain($members['kept']->npub);
});

it('keeps an erased member out of the event subscription', function () {
    /*
     * Two independently sufficient reasons: the authors filter travels to
     * three foreign relays, and NIP-01 requires 64 lowercase hex characters
     * there — a tombstone is not one, and how a relay answers a malformed
     * entry is unverified. The subscription is shared by all members, so a
     * rejected REQ would take everyone down with it.
     */
    $members = erasedAndKeptMember();

    $authors = (new ProbingFetchEvents)->probeSubjectPubkeys();

    expect($authors)->toContain($members['kept']->pubkey)
        ->not->toContain($members['erased']->pubkey);

    // Every remaining entry is a well-formed NIP-01 pubkey.
    foreach ($authors as $author) {
        expect($author)->toBeNostrHexKey();
    }
});
