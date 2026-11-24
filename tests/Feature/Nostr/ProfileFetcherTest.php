<?php

use App\Models\Profile;
use App\Services\Nostr\EventSigning;
use App\Services\Nostr\ProfileFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

/*
 * ProfileFetcher against an in-memory relay. The profiles are real, signed
 * kind-0 events from throwaway keys; the relay set is pinned in config, as
 * tests/Feature/Console/SyncDmRelaysTest.php does it.
 */

const PROFILE_RELAY = 'wss://profiles.example.com';
const SECOND_PROFILE_RELAY = 'wss://profiles-two.example.com';

beforeEach(function () {
    config(['einundzwanzig.config.profile_relays' => [PROFILE_RELAY, SECOND_PROFILE_RELAY]]);
});

/**
 * @param  array<string, mixed>  $content
 * @return array<string, mixed>
 */
function signedProfileEvent(string $secret, array $content, int $createdAt = 1_750_000_000): array
{
    return EventSigning::sign($secret, 0, [], json_encode($content), $createdAt);
}

it('stores the newest authentic profile per author, whatever relay delivered it', function () {
    $alice = testPrivateKey();
    $alicePubkey = (new Key)->getPublicKey($alice);

    $forged = signedProfileEvent($alice, ['name' => 'mallory'], 1_790_000_000);
    $forged['sig'] = str_repeat('0', 128);

    FakeRelayTransport::bind()
        ->withStoredEvents(PROFILE_RELAY, [
            signedProfileEvent($alice, ['name' => 'alice-new', 'about' => 'newest'], 1_760_000_000),
            $forged,
        ])
        ->withStoredEvents(SECOND_PROFILE_RELAY, [
            // Older, and delivered last: must not win, and its picture must not leak into the newer profile.
            signedProfileEvent($alice, ['name' => 'alice-old', 'picture' => 'https://old.example/a.png'], 1_700_000_000),
        ]);

    $stored = app(ProfileFetcher::class)->fetch([$alicePubkey]);

    $profile = Profile::query()->where('pubkey', $alicePubkey)->sole();

    expect($stored)->toBe(1)
        ->and($profile->name)->toBe('alice-new')
        ->and($profile->about)->toBe('newest')
        ->and($profile->picture)->toBeNull();
});

it('asks every relay for kind 0 of the hex keys, npubs converted and junk dropped', function () {
    $alicePubkey = (new Key)->getPublicKey(testPrivateKey());
    $bobPubkey = (new Key)->getPublicKey(testPrivateKey());
    $relay = FakeRelayTransport::bind();

    app(ProfileFetcher::class)->fetch([$alicePubkey, (new Key)->convertPublicKeyToBech32($bobPubkey), 'deleted-abc', 'npub1nope']);

    expect(array_column($relay->fetched(), 'relay'))->toBe([PROFILE_RELAY, SECOND_PROFILE_RELAY])
        ->and($relay->fetched()[0]['filter'])->toBe(['kinds' => [0], 'authors' => [$alicePubkey, $bobPubkey]]);
});

it('asks for at most a hundred authors per request', function () {
    config(['einundzwanzig.config.profile_relays' => [PROFILE_RELAY]]);
    $relay = FakeRelayTransport::bind();
    $pubkeys = array_map(fn (int $i): string => hash('sha256', 'author-'.$i), range(1, 150));

    app(ProfileFetcher::class)->fetch($pubkeys);

    expect(array_map(fn (array $call): int => count($call['filter']['authors']), $relay->fetched()))->toBe([100, 50]);
});

it('reads nothing without configured relays', function () {
    config(['einundzwanzig.config.profile_relays' => []]);
    $relay = FakeRelayTransport::bind();

    expect(app(ProfileFetcher::class)->fetch([hash('sha256', 'someone')]))->toBe(0)
        ->and($relay->fetched())->toBe([]);
});

it('ignores profiles nobody asked for, from the far future, or without a JSON object', function () {
    $asked = testPrivateKey();
    $askedPubkey = (new Key)->getPublicKey($asked);
    $stranger = testPrivateKey();
    $now = CarbonImmutable::now()->getTimestamp();

    FakeRelayTransport::bind()->withStoredEvents(PROFILE_RELAY, [
        signedProfileEvent($stranger, ['name' => 'stranger']),
        signedProfileEvent($asked, ['name' => 'from-the-future'], $now + ProfileFetcher::MAX_FUTURE_SECONDS + 60),
        EventSigning::sign($asked, 0, [], 'not json', $now - 10),
        EventSigning::sign($asked, 0, [], '["a list"]', $now - 20),
    ]);

    expect(app(ProfileFetcher::class)->fetch([$askedPubkey]))->toBe(0)
        ->and(Profile::query()->count())->toBe(0);
});

it('keeps what an unanswering relay delivered before it went quiet', function () {
    $alice = testPrivateKey();
    $alicePubkey = (new Key)->getPublicKey($alice);

    FakeRelayTransport::bind()
        ->withStoredEvents(PROFILE_RELAY, [signedProfileEvent($alice, ['name' => 'alice', 'deleted' => true])])
        ->withUnansweringRelay(PROFILE_RELAY);

    app(ProfileFetcher::class)->fetch([$alicePubkey]);

    $profile = Profile::query()->where('pubkey', $alicePubkey)->sole();

    expect($profile->name)->toBe('alice')
        ->and((bool) $profile->deleted)->toBeTrue();
});

it('stores one profile through nostr:profile, the command once misspelt nostr:pofile', function () {
    $alice = testPrivateKey();
    $alicePubkey = (new Key)->getPublicKey($alice);
    FakeRelayTransport::bind()->withStoredEvents(PROFILE_RELAY, [signedProfileEvent($alice, ['name' => 'alice'])]);

    $this->artisan('nostr:profile', ['--pubkey' => (new Key)->convertPublicKeyToBech32($alicePubkey)])
        ->expectsOutput('profiles_stored=1')
        ->assertSuccessful();

    expect(Profile::query()->where('pubkey', $alicePubkey)->value('name'))->toBe('alice')
        ->and(Artisan::all())->not->toHaveKey('nostr:pofile');
});
