<?php

use App\Services\Nostr\Nip05Directory;
use App\Services\Nostr\Nip05Status;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

/*
 * The association's nostr.json behind a fake. Every test fakes its own answer:
 * the suite refuses any request nobody faked (tests/Pest.php).
 */

const ALICE_HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const BOB_HEX = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

function fakeDirectory(array $names, int $status = 200): void
{
    Http::fake(['einundzwanzig.space/.well-known/nostr.json' => Http::response(['names' => $names], $status)]);
}

it('lists the handles of a pubkey and derives the status from the stored one', function () {
    fakeDirectory(['alice' => ALICE_HEX, 'ali' => ALICE_HEX, 'bob' => BOB_HEX]);
    $directory = app(Nip05Directory::class);

    expect($directory->handlesFor(ALICE_HEX))->toBe(['alice', 'ali'])
        ->and($directory->statusFor(ALICE_HEX, 'alice'))->toEqual(Nip05Status::of('alice', ['alice', 'ali']))
        ->and($directory->statusFor(ALICE_HEX, 'alice')->mismatch)->toBeFalse()
        ->and($directory->statusFor(ALICE_HEX, 'renamed')->mismatch)->toBeTrue()
        ->and($directory->statusFor(ALICE_HEX, 'renamed')->verifiedHandle)->toBe('alice')
        ->and($directory->statusFor(BOB_HEX, null))->toEqual(Nip05Status::none());
});

it('reads the directory once per five minutes', function () {
    fakeDirectory(['alice' => ALICE_HEX]);
    $directory = app(Nip05Directory::class);

    $directory->handlesFor(ALICE_HEX);
    $directory->handlesFor(BOB_HEX);
    Http::assertSentCount(1);

    $this->travel(Nip05Directory::CACHE_SECONDS + 1)->seconds();
    $directory->handlesFor(ALICE_HEX);
    Http::assertSentCount(2);
});

it('reads past the cache when asked for a fresh answer', function () {
    // A sequence, not a second fake: fakes are appended and the first match answers.
    Http::fakeSequence('einundzwanzig.space/*')
        ->push(['names' => ['alice' => ALICE_HEX]])
        ->push(['names' => ['alice' => ALICE_HEX, 'alice2' => ALICE_HEX]]);
    $directory = app(Nip05Directory::class);

    expect($directory->handlesFor(ALICE_HEX))->toBe(['alice'])
        ->and($directory->handlesFor(ALICE_HEX))->toBe(['alice'])
        ->and($directory->handlesFor(ALICE_HEX, fresh: true))->toBe(['alice', 'alice2']);
});

it('gives the request three seconds to connect and three to finish', function () {
    $options = null;
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(['names' => []]);
    });

    app(Nip05Directory::class)->handlesFor(ALICE_HEX);

    expect($options['connect_timeout'])->toBe(Nip05Directory::TIMEOUT_SECONDS)
        ->and($options['timeout'])->toBe(Nip05Directory::TIMEOUT_SECONDS);
});

it('verifies nothing and caches nothing when the directory is unreadable', function (Closure $answer) {
    Http::fake(['einundzwanzig.space/*' => $answer]);
    $directory = app(Nip05Directory::class);

    expect($directory->statusFor(ALICE_HEX, 'alice'))->toEqual(Nip05Status::none());

    $directory->handlesFor(ALICE_HEX);
    Http::assertSentCount(2);
})->with([
    'server error' => [fn () => Http::response('down', 500)],
    'no names map' => [fn () => Http::response(['nope' => true])],
    'no connection' => [fn () => Http::failedConnection()],
]);

it('keeps an unfaked request an error instead of an empty directory', function () {
    Http::fake(['pay.example/*' => Http::response()]);

    app(Nip05Directory::class)->handlesFor(ALICE_HEX);
})->throws(StrayRequestException::class);

it('skips entries whose value is no pubkey string', function () {
    fakeDirectory(['alice' => ALICE_HEX, 'broken' => ['not' => 'a key'], 'bob' => BOB_HEX]);

    expect(app(Nip05Directory::class)->handlesFor(BOB_HEX))->toBe(['bob']);
});
