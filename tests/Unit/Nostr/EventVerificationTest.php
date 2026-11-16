<?php

use App\Services\Nostr\EventSigning;
use App\Services\Nostr\EventVerification;
use swentel\nostr\Event\Event;

/*
 * A received event counts only with its own id and a valid signature. The
 * line-separator case is why this class exists next to the library's
 * Event::verify(): the library calls such an event forged.
 */

function verifiableEvent(string $content = 'hello'): array
{
    return EventSigning::sign(testPrivateKey(), 10050, [['relay', 'wss://inbox.example/']], $content, 1_750_000_000);
}

it('accepts a correctly signed event, a line separator in the content included', function () {
    $event = verifiableEvent("one\u{2028}two");

    expect(EventVerification::isAuthentic($event))->toBeTrue()
        ->and((new Event)->verify(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)))->toBeFalse();
});

it('refuses an event that was changed or never signed', function (Closure $tamper) {
    expect(EventVerification::isAuthentic($tamper(verifiableEvent())))->toBeFalse();
})->with([
    'content changed' => [fn (array $e) => [...$e, 'content' => 'other']],
    'tag changed' => [fn (array $e) => [...$e, 'tags' => [['relay', 'wss://evil.example/']]]],
    'created_at changed' => [fn (array $e) => [...$e, 'created_at' => $e['created_at'] + 1]],
    'signature zeroed' => [fn (array $e) => [...$e, 'sig' => str_repeat('0', 128)]],
    'signature of another event' => [fn (array $e) => [...$e, 'sig' => verifiableEvent()['sig']]],
    'created_at as string' => [fn (array $e) => [...$e, 'created_at' => (string) $e['created_at']]],
    'tag value not a string' => [fn (array $e) => [...$e, 'tags' => [['relay', 1]]]],
    'uppercase id' => [fn (array $e) => [...$e, 'id' => strtoupper($e['id'])]],
    'missing sig' => [fn (array $e) => array_diff_key($e, ['sig' => true])],
]);
