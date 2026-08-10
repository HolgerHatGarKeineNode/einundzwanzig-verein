<?php

use App\Exceptions\Nip98Exception;
use App\Models\EinundzwanzigPleb;
use App\Support\Nip98;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

/**
 * Empirical measurement for P2(b) of the onboarding plan
 * (einundzwanzig-group: docs/plans/2026-08-10T0015-mitglieds-onboarding.md).
 *
 * The plan's assumption: a proxy that re-serialises a NIP-98-signed body
 * before forwarding it — the shape `Http::post($url, $array)` produces —
 * breaks the payload hash and the real endpoint answers `payload_mismatch`.
 * `Nip98::assertPayload()` hashes the RAW body (`$request->getContent()`),
 * so the claim reads true from the code; this file proves it against the
 * actual route with actually different bytes, not against an assumption.
 *
 * Run against the REAL route `POST /api/v1/membership/applications`, on the
 * local test kernel — no network call, no other repo, no production system
 * touched. Client key is a disposable value configured only for this file.
 */
const RESERIALIZATION_CLIENT_KEY = '11223344556677889900aabbccddeeff11223344556677889900aabbccddee';

const APPLICATIONS_URL_PATH = '/api/v1/membership/applications';

beforeEach(function () {
    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => RESERIALIZATION_CLIENT_KEY,
    ]]);
});

/**
 * Sign a POST to the applications endpoint over exactly `$signedBytes`, then
 * put `$sentBytes` on the wire (defaults to the same bytes). This is exactly
 * the shape of a proxy bug: the signature was produced over one
 * representation of the body, but a different representation is what
 * actually travels to the server.
 *
 * Built with `call()` and raw `content`, never `post()`/`postJson()` — those
 * re-encode their data argument themselves, which would make it impossible to
 * say which bytes were actually hashed and which were actually sent.
 *
 * @return array{response: TestResponse, event: array<string, mixed>, pubkey: string}
 */
function reserializationCall(string $signedBytes, ?string $sentBytes = null): array
{
    $signed = makeNip98Event(url(APPLICATIONS_URL_PATH), 'POST', $signedBytes);

    $response = test()->call('POST', APPLICATIONS_URL_PATH, server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_API_KEY' => RESERIALIZATION_CLIENT_KEY,
        'HTTP_AUTHORIZATION' => $signed['header'],
    ], content: $sentBytes ?? $signedBytes);

    return [
        'response' => $response,
        'event' => $signed['event'],
        'pubkey' => $signed['pubkey'],
    ];
}

/**
 * Run the signed event through Nip98::verify() directly, against exactly the
 * bytes that were sent, and return why it was refused — the same
 * "assert twice" pattern Nip98Test.php uses, so a 401 can be attributed to
 * the payload check specifically and not to an earlier guard.
 *
 * @param  array<string, mixed>  $event
 */
function applicationsNip98Reason(array $event, string $sentBody): string
{
    $request = Request::create(
        url(APPLICATIONS_URL_PATH),
        'POST',
        server: [
            'HTTP_AUTHORIZATION' => nip98Header($event),
            'CONTENT_TYPE' => 'application/json',
        ],
        content: $sentBody,
    );

    try {
        Nip98::verify($request);
    } catch (Nip98Exception $e) {
        return $e->reason;
    }

    return 'accepted';
}

/**
 * A body chosen so re-serialisation actually changes the bytes: a forward
 * slash (a URL, plausible free text for "why I want to join") and a German
 * umlaut. JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE approximates what a
 * browser's JSON.stringify() puts on the wire (unescaped `/`, literal UTF-8);
 * plain json_encode() — what Guzzle's `json` request option uses via
 * GuzzleHttp\Utils::jsonEncode($value, 0) inside Http::post(), confirmed by
 * reading vendor/laravel/framework/.../PendingRequest.php (bodyFormat
 * 'json') and vendor/guzzlehttp/guzzle/src/Utils.php (default $options = 0)
 * — escapes both. That escaping difference is the whole mechanism under
 * test.
 *
 * @return array{string, string}
 */
function slashAndUnicodeBodyBytes(): array
{
    $body = [
        'statutes_accepted' => true,
        'application_text' => 'Mehr Infos: https://einundzwanzig.space/verein — für Mitglieder',
    ];

    $originalBytes = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $reserializedBytes = json_encode(json_decode($originalBytes, true));

    return [$originalBytes, $reserializedBytes];
}

it('lets a raw, byte-exact body pass the payload check on the real endpoint', function () {
    [$originalBytes] = slashAndUnicodeBodyBytes();

    $call = reserializationCall($originalBytes);

    // Not merely "not 401": the request clears NIP-98 (payload included),
    // form validation AND persistence. Stopping at "not 401" would not
    // distinguish "passed the payload check" from "failed somewhere else
    // that happens to also answer non-401".
    $call['response']->assertCreated();

    expect(EinundzwanzigPleb::query()->where('pubkey', $call['pubkey'])->exists())->toBeTrue();
});

it('answers payload_mismatch when the body is re-serialised the way Http::post($url, $array) would send it', function () {
    [$originalBytes, $reserializedBytes] = slashAndUnicodeBodyBytes();

    // Sanity check on the premise, not on the endpoint: if this body happened
    // to re-serialise identically, the test below would prove nothing.
    expect($reserializedBytes)->not->toBe($originalBytes);

    $call = reserializationCall($originalBytes, sentBytes: $reserializedBytes);

    $call['response']->assertUnauthorized();

    expect(applicationsNip98Reason($call['event'], $reserializedBytes))
        ->toBe(Nip98Exception::PAYLOAD_MISMATCH);

    // The point at which this fails is a 401 in front of the controller —
    // nothing gets written for a body that never matched what was signed.
    expect(EinundzwanzigPleb::query()->count())->toBe(0);
});

it('finds a realistic body that survives re-serialisation byte-for-byte, and is therefore no counter-evidence', function () {
    // The interesting edge of the question: JSON_UNESCAPED_SLASHES and
    // JSON_UNESCAPED_UNICODE only change output for `/` and non-ASCII bytes.
    // A body with neither — pure ASCII, no slash, e.g. a bare consent with no
    // free text yet — re-serialises to identical bytes. That does not mean
    // the proxy can skip raw pass-through: it means this ONE body shape is
    // accidentally safe, not that the mechanism is.
    $body = ['statutes_accepted' => true];

    $originalBytes = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $reserializedBytes = json_encode(json_decode($originalBytes, true));

    expect($reserializedBytes)->toBe($originalBytes);

    // Signed for the original bytes, "sent" as the re-serialised ones — since
    // they are byte-identical this is not a real re-serialisation, and the
    // payload check cannot and does not distinguish the two.
    $call = reserializationCall($originalBytes, sentBytes: $reserializedBytes);

    $call['response']->assertCreated();
});
