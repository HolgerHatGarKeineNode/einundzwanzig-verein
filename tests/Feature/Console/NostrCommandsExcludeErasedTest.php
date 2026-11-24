<?php

use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Artisan;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

/*
 * A SCHEDULED COMMAND CARRIES MEMBER KEYS OUT OF THE HOUSE, and an erased
 * record used to travel with it. `DELETE /api/v1/membership/me` anonymises
 * instead of deleting, so the row survives — and a filter that sits only on the
 * public HTTP list leaves every other exit open.
 */

/**
 * Runs `sync:profiles` against an in-memory relay and returns the authors it
 * asked that relay for.
 *
 * Observing the REAL request rather than re-running the command's query in the
 * test: a test that rebuilds the query proves the test's query right, not the
 * command's.
 *
 * @return array<int, string>
 */
function authorsSentToProfileRelays(): array
{
    config(['einundzwanzig.config.profile_relays' => ['wss://profiles.example.com']]);
    $relay = FakeRelayTransport::bind();

    Artisan::call('sync:profiles');

    return array_merge([], ...array_map(fn (array $call): array => $call['filter']['authors'], $relay->fetched()));
}

/**
 * A member who has asked to be erased, and one who has not.
 *
 * @return array{erased: EinundzwanzigPleb, kept: EinundzwanzigPleb}
 */
function erasedAndKeptMember(): array
{
    $erasedPubkey = (new Key)->getPublicKey(testPrivateKey());
    $keptPubkey = (new Key)->getPublicKey(testPrivateKey());

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

    $sent = authorsSentToProfileRelays();

    expect($sent)->not->toContain($members['erased']->pubkey)
        ->and(collect($sent)->filter(
            fn (string $pubkey): bool => str_starts_with($pubkey, EinundzwanzigPleb::TOMBSTONE_PREFIX)
        )->all())->toBe([]);
});

it('still syncs a member who has not been erased', function () {
    // The discriminating half: a command that sent nothing at all would
    // satisfy the test above and quietly stop syncing anybody.
    $members = erasedAndKeptMember();

    Profile::query()->where('pubkey', $members['kept']->pubkey)->delete();

    expect(authorsSentToProfileRelays())->toContain($members['kept']->pubkey);
});
