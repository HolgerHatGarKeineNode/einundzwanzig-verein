<?php

use App\Models\EinundzwanzigPleb;
use App\Services\Nostr\DirectMessageNotBuildable;
use App\Services\Nostr\DirectMessageSigner;
use App\Services\Nostr\EventHash;
use App\Services\Nostr\GiftWrappedMessage;
use App\Services\Nostr\NotificationKey;
use App\Services\Nostr\NotificationKeyUnavailable;
use Carbon\CarbonImmutable;
use Mdanter\Ecc\Crypto\Signature\SchnorrSignature;
use swentel\nostr\Encryption\Nip44;

/*
 * The keys are the published example keys of NIP-59 ("An Example":
 * https://github.com/nostr-protocol/nips/blob/master/59.md). They are public
 * on purpose, and they make the rumor id a fixed vector: it depends on the
 * sender's pubkey, the time, the tags and the text only.
 *
 * The expected ids were computed with nostr-tools 2.25.2 (node_modules of the
 * main checkout) on 2026-11-14:
 *
 *     node rumor-id.mjs <node_modules>
 *     getEventHash({pubkey: getPublicKey(author), created_at: 1700000000,
 *       kind: 14, tags: [['p', getPublicKey(recipient)]], content})
 *
 * with content = 'Line one' U+2028 'line two' U+2029 'end "quoted" \ back/slash'
 * and, for the control, content = 'plain'. The recipient pubkey it derived,
 * 166bf376…7c99, is the one NIP-59 prints in its example wrap — the keys
 * were read correctly.
 */
const NIP59_AUTHOR_SECRET = '0beebd062ec8735f4243466049d7747ef5d6594ee838de147f8aab842b15e273';
const NIP59_AUTHOR_PUBKEY = '611df01bfcf85c26ae65453b772d8f1dfd25c264621c0277e1fc1518686faef9';
const NIP59_RECIPIENT_SECRET = 'e108399bd8424357a710b606ae0c13166d853d327e47a6e5e038197346bdbf45';
const NIP59_RECIPIENT_PUBKEY = '166bf3765ebd1fc55decfe395beff2ea3b2a4e0a8946e7eb578512b555737c99';
const NOSTR_TOOLS_ID_LINE_TERMINATORS = '9fab907039e2d970e3a44106c59bf02fc2ae4cb6e0486cc114dd749b46ef7a8f';
const NOSTR_TOOLS_ID_PLAIN = '069ef9a0df466cc609469ae810fb073b3392780ae2ca1b1586e9d375eaef8e24';

function nip59Recipient(?string $pubkey = NIP59_RECIPIENT_PUBKEY): EinundzwanzigPleb
{
    return (new EinundzwanzigPleb)->forceFill(['pubkey' => $pubkey]);
}

function nip59Signer(?string $secret = NIP59_AUTHOR_SECRET): DirectMessageSigner
{
    return new DirectMessageSigner(new NotificationKey($secret));
}

/**
 * Open the wrap the way the recipient's client does.
 *
 * @return array{seal: array<string, mixed>, rumor: array<string, mixed>}
 */
function unwrapAsRecipient(GiftWrappedMessage $message): array
{
    $wrap = $message->wrap;
    $seal = json_decode(
        Nip44::decrypt($wrap['content'], Nip44::getConversationKey(NIP59_RECIPIENT_SECRET, $wrap['pubkey'])),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $rumor = json_decode(
        Nip44::decrypt($seal['content'], Nip44::getConversationKey(NIP59_RECIPIENT_SECRET, $seal['pubkey'])),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return ['seal' => $seal, 'rumor' => $rumor];
}

it('wraps a rumor whose id is the recomputed NIP-01 hash and that carries no signature', function () {
    $message = nip59Signer()->wrapFor(nip59Recipient(), 'Die Mitgliederversammlung beginnt um 19 Uhr.');

    ['rumor' => $rumor] = unwrapAsRecipient($message);

    expect($message->rumorId)->toMatch('/^[0-9a-f]{64}$/')
        ->and($rumor['id'])->toBe($message->rumorId)
        ->and($rumor['id'])->toBe(EventHash::of(
            $rumor['pubkey'], $rumor['created_at'], $rumor['kind'], $rumor['tags'], $rumor['content'],
        ))
        ->and($rumor)->not->toHaveKey('sig')
        ->and($rumor['kind'])->toBe(14)
        ->and($rumor['pubkey'])->toBe(NIP59_AUTHOR_PUBKEY)
        ->and($rumor['tags'])->toBe([['p', NIP59_RECIPIENT_PUBKEY]])
        ->and($rumor['content'])->toBe('Die Mitgliederversammlung beginnt um 19 Uhr.')
        ->and($rumor['created_at'])->toBe($message->rumorCreatedAt);
});

it('seals with the notification key and wraps with a one-time key, both signatures valid', function () {
    $message = nip59Signer()->wrapFor(nip59Recipient(), 'Hallo');

    ['seal' => $seal] = unwrapAsRecipient($message);
    $wrap = $message->wrap;
    $schnorr = new SchnorrSignature;

    expect($seal['kind'])->toBe(13)
        ->and($seal['tags'])->toBe([])
        ->and($seal['pubkey'])->toBe(NIP59_AUTHOR_PUBKEY)
        ->and($seal['id'])->toBe(EventHash::of($seal['pubkey'], $seal['created_at'], 13, [], $seal['content']))
        ->and($schnorr->verify($seal['pubkey'], $seal['sig'], $seal['id']))->toBeTrue()
        ->and($wrap['kind'])->toBe(1059)
        ->and($wrap['tags'])->toBe([['p', NIP59_RECIPIENT_PUBKEY]])
        ->and($wrap['pubkey'])->not->toBe(NIP59_AUTHOR_PUBKEY)
        ->and($wrap['id'])->toBe(EventHash::of($wrap['pubkey'], $wrap['created_at'], 1059, $wrap['tags'], $wrap['content']))
        ->and($schnorr->verify($wrap['pubkey'], $wrap['sig'], $wrap['id']))->toBeTrue()
        ->and($message->recipientPubkey)->toBe(NIP59_RECIPIENT_PUBKEY);
});

it('back-dates seal and wrap independently, within two days', function () {
    $sealTimes = [];

    for ($run = 0; $run < 5; $run++) {
        $before = CarbonImmutable::now()->getTimestamp();
        $message = nip59Signer()->wrapFor(nip59Recipient(), 'Hallo');
        $after = CarbonImmutable::now()->getTimestamp();

        ['seal' => $seal] = unwrapAsRecipient($message);
        $wrapTime = $message->wrap['created_at'];

        expect($seal['created_at'])->toBeLessThan($after)
            ->toBeGreaterThanOrEqual($before - DirectMessageSigner::MAX_BACKDATE_SECONDS)
            ->and($wrapTime)->toBeLessThan($after)
            ->toBeGreaterThanOrEqual($before - DirectMessageSigner::MAX_BACKDATE_SECONDS)
            ->and($wrapTime)->not->toBe($seal['created_at'])
            ->and($message->rumorCreatedAt)->toBeGreaterThanOrEqual($before)->toBeLessThanOrEqual($after);

        $sealTimes[] = $seal['created_at'];
    }

    // A fixed offset would pass every bound above; randomness shows across runs.
    expect(array_unique($sealTimes))->not->toHaveCount(1);
});

it('computes the rumor id nostr-tools computes, also for U+2028 and U+2029', function (string $text, string $expectedId) {
    $message = nip59Signer()->wrapFor(nip59Recipient(), $text, 1_700_000_000);

    expect($message->rumorId)->toBe($expectedId)
        ->and(unwrapAsRecipient($message)['rumor']['content'])->toBe($text);
})->with([
    'line and paragraph separator' => ["Line one\u{2028}line two\u{2029}end \"quoted\" \\ back/slash", NOSTR_TOOLS_ID_LINE_TERMINATORS],
    'plain text' => ['plain', NOSTR_TOOLS_ID_PLAIN],
]);

it('re-wraps the same rumor in a fresh wrap when given the stored time', function () {
    $first = nip59Signer()->wrapFor(nip59Recipient(), 'Erinnerung', 1_700_000_000);
    $retry = nip59Signer()->wrapFor(nip59Recipient(), 'Erinnerung', 1_700_000_000);

    expect($retry->rumorId)->toBe($first->rumorId)
        ->and($retry->wrap['id'])->not->toBe($first->wrap['id'])
        ->and($retry->wrap['pubkey'])->not->toBe($first->wrap['pubkey']);
});

it('refuses what it cannot address or carry', function (EinundzwanzigPleb $recipient, string $text, ?int $createdAt) {
    expect(fn () => nip59Signer()->wrapFor($recipient, $text, $createdAt))
        ->toThrow(DirectMessageNotBuildable::class);
})->with([
    'erased member' => fn () => [nip59Recipient(EinundzwanzigPleb::TOMBSTONE_PREFIX.str_repeat('a', 56)), 'Hallo', null],
    'npub instead of hex' => fn () => [nip59Recipient('npub1'.str_repeat('q', 58)), 'Hallo', null],
    'uppercase hex' => fn () => [nip59Recipient(strtoupper(NIP59_RECIPIENT_PUBKEY)), 'Hallo', null],
    'x above the field prime' => fn () => [nip59Recipient(str_repeat('f', 64)), 'Hallo', null],
    'x without a curve point' => fn () => [nip59Recipient(str_repeat('0', 63).'5'), 'Hallo', null],
    'empty text' => fn () => [nip59Recipient(), "  \n", null],
    'invalid UTF-8' => fn () => [nip59Recipient(), "caf\xE9", null],
    'text over the limit' => fn () => [nip59Recipient(), str_repeat('a', DirectMessageSigner::MAX_TEXT_BYTES + 1), null],
    'rumor time in the future' => fn () => [nip59Recipient(), 'Hallo', CarbonImmutable::now()->getTimestamp() + 60],
    'rumor time zero' => fn () => [nip59Recipient(), 'Hallo', 0],
]);

it('keeps a wrap of the longest allowed text below 64 KiB', function () {
    $message = nip59Signer()->wrapFor(nip59Recipient(), str_repeat('ä', DirectMessageSigner::MAX_TEXT_BYTES / 2));

    expect(strlen(EventHash::json(['EVENT', $message->wrap])))->toBeLessThan(65536);
});

it('refuses a short text whose encoding outgrows NIP-44 or the frame limit, with its own exception', function (string $text, string $part) {
    expect(strlen($text))->toBeLessThanOrEqual(DirectMessageSigner::MAX_TEXT_BYTES)
        ->and(fn () => nip59Signer()->wrapFor(nip59Recipient(), $text))
        ->toThrow(DirectMessageNotBuildable::class, "The {$part} is ");
})->with([
    '11,000 x U+0001: the rumor JSON passes 65,535 bytes' => [str_repeat("\u{0001}", 11_000), 'encoded rumor'],
    '8,000 x U+0001: the seal JSON passes 65,535 bytes' => [str_repeat("\u{0001}", 8_000), 'encoded seal'],
    '16,384 x quote: the EVENT frame passes 65,536 bytes' => [str_repeat('"', 16_384), 'gift wrap EVENT frame'],
]);

it('throws the key error, not a recipient error, when no key is configured', function () {
    expect(fn () => nip59Signer(null)->wrapFor(nip59Recipient(), 'Hallo'))
        ->toThrow(NotificationKeyUnavailable::class);
});

it('offers exactly one public method, which takes a member and a text but no kind, tag or key', function () {
    $class = new ReflectionClass(DirectMessageSigner::class);

    $methods = array_values(array_map(
        fn (ReflectionMethod $method): string => $method->getName(),
        array_filter(
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
            fn (ReflectionMethod $method): bool => ! $method->isConstructor(),
        ),
    ));

    $parameters = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getType().' $'.$parameter->getName(),
        $class->getMethod('wrapFor')->getParameters(),
    );

    expect($methods)->toBe(['wrapFor'])
        ->and($parameters)->toBe([EinundzwanzigPleb::class.' $recipient', 'string $plaintext', '?int $rumorCreatedAt']);
});
