<?php

use App\Console\Commands\Nostr\SyncDmRelays;
use App\Models\EinundzwanzigPleb;
use App\Models\PlebDmRelay;
use App\Services\MembershipService;
use App\Services\Nostr\EventSigning;
use Carbon\CarbonImmutable;
use swentel\nostr\Key\Key;
use Tests\Support\FakeRelayTransport;

/*
 * nostr:sync-dm-relays against an in-memory relay. The lists are real,
 * signed kind 10050 events from throwaway keys; the relay set is pinned in
 * config so the command has somewhere to read from.
 */

const INDEXER = 'wss://indexer.example.com';
const SECOND_INDEXER = 'wss://second.example.com';

beforeEach(function () {
    config(['einundzwanzig.config.profile_relays' => [INDEXER, SECOND_INDEXER]]);
});

/**
 * A member with a fresh key pair.
 *
 * @return array{0: EinundzwanzigPleb, 1: string}
 */
function memberWithKey(): array
{
    $secret = testPrivateKey();

    return [EinundzwanzigPleb::factory()->create(['pubkey' => (new Key)->getPublicKey($secret)]), $secret];
}

/**
 * @param  array<int, string>  $relays
 * @return array<string, mixed>
 */
function dmRelayList(string $secret, array $relays, int $createdAt = 1_750_000_000): array
{
    return EventSigning::sign($secret, 10050, array_map(fn (string $url): array => ['relay', $url], $relays), '', $createdAt);
}

it('counts in a dry run and stores nothing', function () {
    [$alice, $aliceKey] = memberWithKey();
    [$bob] = memberWithKey();
    memberWithKey();
    $relay = FakeRelayTransport::bind()->withStoredEvents(INDEXER, [dmRelayList($aliceKey, ['wss://inbox.alice.example/'])]);

    $this->artisan('nostr:sync-dm-relays', ['--dry-run' => true])
        ->expectsOutput('members=3 with_inbox=1 without_inbox=2')
        ->assertSuccessful();

    expect(PlebDmRelay::query()->count())->toBe(0)
        ->and($relay->fetched())->toHaveCount(2)
        ->and($relay->fetched()[0]['filter']['kinds'])->toBe([10050])
        ->and($relay->fetched()[0]['filter']['authors'])->toContain($alice->pubkey, $bob->pubkey)
        ->and(array_column($relay->fetched(), 'relay'))->toBe([INDEXER, SECOND_INDEXER]);
    $relay->assertNothingPublished();
});

it('stores the newest valid list per member, normalised and capped at three relays', function () {
    [$alice, $aliceKey] = memberWithKey();
    [$bob, $bobKey] = memberWithKey();
    $forged = dmRelayList($bobKey, ['wss://evil.example/'], 1_790_000_000);
    $forged['tags'] = [['relay', 'wss://evil.example/']];
    $forged['sig'] = str_repeat('0', 128);

    FakeRelayTransport::bind()
        ->withStoredEvents(INDEXER, [
            dmRelayList($aliceKey, ['wss://old.example/'], 1_700_000_000),
            dmRelayList($bobKey, ['wss://bob.example/'], 1_700_000_000),
            $forged,
        ])
        ->withStoredEvents(SECOND_INDEXER, [
            dmRelayList($aliceKey, [
                ' WSS://Inbox.Example/ ', 'wss://inbox.example/', 'ws://plain.example', 'https://web.example',
                'wss://two.example', 'wss://three.example:8443/dm', 'wss://four.example',
            ], 1_750_000_000),
        ]);

    $this->artisan('nostr:sync-dm-relays')
        ->expectsOutput('members=2 with_inbox=2 without_inbox=0')
        ->assertSuccessful();

    expect($alice->dmRelays->relays)->toBe(['wss://inbox.example/', 'wss://two.example/', 'wss://three.example:8443/dm'])
        ->and($alice->dmRelays->event_created_at)->toBe(1_750_000_000)
        ->and($bob->dmRelays->relays)->toBe(['wss://bob.example/']);
});

it('ignores lists of non-members, of other kinds, of erased members and lists dated into the future', function () {
    [$alice, $aliceKey] = memberWithKey();
    [$erased, $erasedKey] = memberWithKey();
    $erasedList = dmRelayList($erasedKey, ['wss://erased.example/']);
    app(MembershipService::class)->erasePersonalData($erased);
    $future = CarbonImmutable::now()->getTimestamp() + SyncDmRelays::MAX_FUTURE_SECONDS + 60;

    FakeRelayTransport::bind()->withStoredEvents(INDEXER, [
        dmRelayList(testPrivateKey(), ['wss://stranger.example/']),
        EventSigning::sign($aliceKey, 10002, [['relay', 'wss://outbox.example/']], '', 1_750_000_000),
        dmRelayList($aliceKey, ['wss://future.example/'], $future),
        $erasedList,
    ]);

    $this->artisan('nostr:sync-dm-relays')
        ->expectsOutput('members=1 with_inbox=0 without_inbox=1')
        ->assertSuccessful();

    expect(PlebDmRelay::query()->count())->toBe(0);
});

it('never replaces a stored list with an older one and drops a member whose newest list is empty', function () {
    [$alice, $aliceKey] = memberWithKey();
    [$bob, $bobKey] = memberWithKey();
    PlebDmRelay::factory()->for($alice, 'pleb')->create(['relays' => ['wss://current.example/'], 'event_created_at' => 1_760_000_000]);
    PlebDmRelay::factory()->for($bob, 'pleb')->create(['event_created_at' => 1_700_000_000]);

    FakeRelayTransport::bind()->withStoredEvents(INDEXER, [
        dmRelayList($aliceKey, ['wss://stale.example/'], 1_750_000_000),
        dmRelayList($bobKey, ['ws://only-plain.example'], 1_750_000_000),
    ]);

    $this->artisan('nostr:sync-dm-relays')->assertSuccessful();

    expect($alice->fresh()->dmRelays->relays)->toBe(['wss://current.example/'])
        ->and($bob->fresh()->dmRelays)->toBeNull();
});

it('drops rows of members without a list only when every read was complete', function (bool $secondAnswers, bool $kept) {
    [$alice] = memberWithKey();
    PlebDmRelay::factory()->for($alice, 'pleb')->create();
    $relay = FakeRelayTransport::bind();

    if (! $secondAnswers) {
        $relay->withUnansweringRelay(SECOND_INDEXER);
    }

    $this->artisan('nostr:sync-dm-relays')->assertSuccessful();

    expect($alice->fresh()->dmRelays !== null)->toBe($kept);
})->with([
    'all reads complete' => [true, false],
    'one relay timed out' => [false, true],
]);

it('refuses to run without relays to read from', function () {
    config(['einundzwanzig.config.profile_relays' => []]);
    $relay = FakeRelayTransport::bind();

    $this->artisan('nostr:sync-dm-relays', ['--dry-run' => true])->assertFailed();

    expect($relay->fetched())->toBe([]);
});

it('deletes the inbox relays when a member is erased', function () {
    [$alice] = memberWithKey();
    [$bob] = memberWithKey();
    PlebDmRelay::factory()->for($alice, 'pleb')->create();
    PlebDmRelay::factory()->for($bob, 'pleb')->create();

    app(MembershipService::class)->erasePersonalData($alice);

    expect(PlebDmRelay::query()->pluck('einundzwanzig_pleb_id')->all())->toBe([$bob->id]);
});
