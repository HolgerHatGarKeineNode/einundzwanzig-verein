<?php

use App\Services\Nostr\RelayPublishStatus;
use App\Services\Nostr\RelayTransport;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\FakeRelayTransport;

/*
 * The fake is what later tests take a relay's word from. A double that
 * accepts when it was told to reject, or an assertion that cannot fail, would
 * turn those tests green for nothing — so its contract is pinned here once.
 */

it('answers through the container once bound, and accepts an event nobody scripted', function () {
    $relay = FakeRelayTransport::bind();

    $result = app(RelayTransport::class)->publish('wss://one.example/', ['kind' => 1059, 'id' => 'a']);

    expect($result->accepted)->toBeTrue()
        ->and($relay->published())->toBe([
            ['relay' => 'wss://one.example/', 'event' => ['kind' => 1059, 'id' => 'a'], 'accepted' => true],
        ]);
});

it('plays scripted outcomes in order, one per publish, then accepts again', function () {
    $relay = FakeRelayTransport::bind()
        ->thenReject('blocked: not on the allow list')
        ->thenAccept();

    $first = $relay->publish('wss://one.example/', ['kind' => 1, 'id' => 'a']);
    $second = $relay->publish('wss://two.example/', ['kind' => 1, 'id' => 'a']);
    $third = $relay->publish('wss://one.example/', ['kind' => 1, 'id' => 'b']);

    expect([$first->accepted, $first->message])->toBe([false, 'blocked: not on the allow list'])
        ->and($second->accepted)->toBeTrue()
        ->and($third->accepted)->toBeTrue()
        ->and(array_column($relay->published(), 'accepted'))->toBe([false, true, true]);
});

it('throws a scripted failure and records whether the relay had taken the event', function () {
    $relay = FakeRelayTransport::bind()
        ->thenThrow(new RuntimeException('connection refused'))
        ->thenAcceptAndThrow(new RuntimeException('connection lost after OK'));

    expect(fn () => $relay->publish('wss://one.example/', ['kind' => 1, 'id' => 'a']))
        ->toThrow(RuntimeException::class, 'connection refused')
        ->and(fn () => $relay->publish('wss://one.example/', ['kind' => 1, 'id' => 'a']))
        ->toThrow(RuntimeException::class, 'connection lost after OK')
        ->and(array_column($relay->published(), 'accepted'))->toBe([false, true]);
});

it('returns the events stored for the asked relay only and records the filter', function () {
    $relay = FakeRelayTransport::bind()
        ->withStoredEvents('wss://one.example/', [['kind' => 10050, 'id' => 'inbox']]);

    $one = $relay->fetch('wss://one.example/', ['kinds' => [10050]]);
    $two = $relay->fetch('wss://two.example/', ['kinds' => [10050]]);

    expect([$one->events, $one->complete])->toBe([[['kind' => 10050, 'id' => 'inbox']], true])
        ->and([$two->events, $two->complete])->toBe([[], true])
        ->and($relay->fetched())->toBe([
            ['relay' => 'wss://one.example/', 'filter' => ['kinds' => [10050]]],
            ['relay' => 'wss://two.example/', 'filter' => ['kinds' => [10050]]],
        ]);
});

it('reports an unanswering relay as incomplete, keeping what it had sent', function () {
    $relay = FakeRelayTransport::bind()
        ->withStoredEvents('wss://slow.example/', [['kind' => 10050, 'id' => 'partial']])
        ->withUnansweringRelay('wss://slow.example/', 'timeout: no EOSE within 10 s');

    $result = $relay->fetch('wss://slow.example/', ['kinds' => [10050]]);

    expect([$result->events, $result->complete, $result->message])
        ->toBe([[['kind' => 10050, 'id' => 'partial']], false, 'timeout: no EOSE within 10 s']);
});

it('plays failed and refused outcomes as results with their own status', function () {
    $relay = FakeRelayTransport::bind()
        ->thenFail('timeout: no OK within 10 s')
        ->thenRefuse('refused: private address');

    $failed = $relay->publish('wss://one.example/', ['kind' => 1059, 'id' => 'a']);
    $refused = $relay->publish('wss://one.example/', ['kind' => 1059, 'id' => 'a']);

    expect([$failed->status, $failed->accepted, $failed->message])
        ->toBe([RelayPublishStatus::Failed, false, 'timeout: no OK within 10 s'])
        ->and([$refused->status, $refused->accepted])->toBe([RelayPublishStatus::Refused, false]);
});

it('fails its own assertions when the count is off', function () {
    $relay = FakeRelayTransport::bind();

    $relay->assertNothingPublished();
    $relay->assertPublishedCount(0);

    $relay->publish('wss://one.example/', ['kind' => 1059, 'id' => 'a']);

    expect(fn () => $relay->assertNothingPublished())
        ->toThrow(AssertionFailedError::class, 'Events were handed to a relay although none were expected.')
        ->and(fn () => $relay->assertPublishedCount(2))
        ->toThrow(AssertionFailedError::class);

    $relay->assertPublishedCount(1);
});
