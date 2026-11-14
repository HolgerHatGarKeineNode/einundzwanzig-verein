<?php

use App\Services\Nostr\EventHash;
use App\Services\Nostr\NotificationKey;
use App\Services\Nostr\NotificationKeyUnavailable;
use Mdanter\Ecc\Crypto\Signature\SchnorrSignature;
use swentel\nostr\Key\Key;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

it('throws a typed exception when no notification key is configured', function () {
    config()->set('services.nostr_notification_key', '');

    expect(fn () => app(NotificationKey::class)->publicKey())
        ->toThrow(NotificationKeyUnavailable::class, 'No notification key is configured');
});

it('does not fall back to the fee-signing key', function () {
    config()->set('services.nostr', testPrivateKey());
    config()->set('services.nostr_notification_key', null);

    expect(fn () => app(NotificationKey::class)->signSeal('ciphertext', time()))
        ->toThrow(NotificationKeyUnavailable::class);
});

it('refuses a malformed key without repeating it', function (string $configured) {
    $key = new NotificationKey($configured);

    try {
        $key->publicKey();
        $this->fail('A malformed key was accepted.');
    } catch (NotificationKeyUnavailable $exception) {
        expect($exception->getMessage())->toContain('neither an nsec')
            ->not->toContain($configured);
    }
})->with([
    'too short' => [str_repeat('ab', 31)],
    'not hex' => [str_repeat('zz', 32)],
    'zero' => [str_repeat('0', 64)],
    'curve order' => ['fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141'],
    'broken nsec' => ['nsec1notarealkeyatall'],
]);

it('accepts the hex and the nsec form of the same key', function () {
    $secret = testPrivateKey();
    $nsec = (new Key)->convertPrivateKeyToBech32($secret);

    config()->set('services.nostr_notification_key', $nsec);

    expect(app(NotificationKey::class)->publicKey())
        ->toBe((new NotificationKey(strtoupper($secret)))->publicKey())
        ->toBe((new Key)->getPublicKey($secret));
});

it('signs a seal as kind 13 with empty tags and a valid signature', function () {
    $secret = testPrivateKey();
    $key = new NotificationKey($secret);

    $seal = $key->signSeal('ciphertext', 1_700_000_000);

    expect($seal['kind'])->toBe(13)
        ->and($seal['tags'])->toBe([])
        ->and($seal['pubkey'])->toBe((new Key)->getPublicKey($secret))
        ->and($seal['id'])->toBe(EventHash::of($seal['pubkey'], 1_700_000_000, 13, [], 'ciphertext'))
        ->and((new SchnorrSignature)->verify($seal['pubkey'], $seal['sig'], $seal['id']))->toBeTrue();
});

it('signs a relay AUTH answer as kind 22242 naming relay and challenge', function () {
    $key = new NotificationKey(testPrivateKey());

    $auth = $key->signRelayAuth('wss://relay.example.com', 'challenge-123', 1_700_000_000);

    expect($auth['kind'])->toBe(22242)
        ->and($auth['tags'])->toBe([['relay', 'wss://relay.example.com'], ['challenge', 'challenge-123']])
        ->and($auth['content'])->toBe('')
        ->and((new SchnorrSignature)->verify($auth['pubkey'], $auth['sig'], $auth['id']))->toBeTrue();
});

it('keeps the secret out of a dump', function () {
    $secret = testPrivateKey();

    expect(print_r(new NotificationKey($secret), true))->not->toContain($secret)
        ->and(print_r(new NotificationKey($secret), true))->toContain('yes');
});

function varDumperOutput(mixed $value): string
{
    $memory = fopen('php://memory', 'r+');
    (new CliDumper($memory))->dump((new VarCloner)->cloneVar($value));
    rewind($memory);

    return (string) stream_get_contents($memory);
}

it('keeps the configured and the derived secret out of dump(), var_export() and an array cast', function (bool $nsec, bool $used) {
    $hex = testPrivateKey();
    $nsecForm = (new Key)->convertPrivateKeyToBech32($hex);
    $key = new NotificationKey($nsec ? $nsecForm : $hex);

    if ($used) {
        $key->publicKey();
    }

    $outputs = [
        'dump' => varDumperOutput($key),
        'var_export' => var_export($key, true),
        '(array)' => print_r((array) $key, true),
    ];

    // The channels themselves do show a secret that sits in a plain property.
    $plain = (object) ['secret' => $hex];
    expect(varDumperOutput($plain))->toContain($hex)
        ->and(var_export($plain, true))->toContain($hex)
        ->and(print_r((array) $plain, true))->toContain($hex);

    foreach ($outputs as $channel => $output) {
        expect(str_contains($output, $hex))->toBeFalse("hex secret visible through {$channel}")
            ->and(str_contains($output, $nsecForm))->toBeFalse("nsec visible through {$channel}");
    }
})->with([
    'hex, before first use' => [false, false],
    'hex, after first use' => [false, true],
    'nsec, after first use' => [true, true],
]);

it('refuses to be serialized, before and after first use', function (bool $used) {
    $key = new NotificationKey(testPrivateKey());

    if ($used) {
        $key->publicKey();
    }

    expect(fn () => serialize($key))->toThrow(LogicException::class, 'cannot be serialized');
})->with(['before first use' => false, 'after first use' => true]);
