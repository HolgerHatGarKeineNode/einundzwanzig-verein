<?php

namespace App\Support;

use App\Exceptions\Nip98Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use swentel\nostr\Event\Event as NostrEvent;
use Throwable;

/**
 * Stateless verification of a NIP-98 HTTP-Auth event
 * (<https://github.com/nostr-protocol/nips/blob/master/98.md>).
 *
 * The client sends `Authorization: Nostr <base64(JSON of a kind-27235 event)>`.
 * The signed pubkey is the ONLY admissible answer to "whose membership is
 * this about" — never a value taken from the path or the body.
 *
 * `NostrAuth::verifySignedEvent()` is the model for the crypto part but is
 * deliberately NOT reused: it verifies against a challenge stored in the
 * session and consumes that challenge, i.e. it is stateful by construction.
 * NIP-98 has no session — the only shared ground is `Event::verify()`.
 *
 * Every condition has its own failure path (see the constants on
 * Nip98Exception). Cheap structural checks run before the two expensive
 * cryptographic ones, so an unsigned flood costs a hash comparison rather
 * than a Schnorr verification — and so a negative test for, say, the `u` tag
 * provably fails on the `u` tag.
 */
class Nip98
{
    public const EVENT_KIND = 27235;

    /**
     * NIP-98 recommends a 60-second window. It bounds clock skew, not replay:
     * inside the window the very same event is still perfectly valid, which
     * is why consume() exists.
     */
    public const MAX_CLOCK_SKEW_SECONDS = 60;

    /**
     * The replay lock has to outlive the acceptance window in both
     * directions — an event stamped 60 s in the future stays acceptable for
     * another 120 s of wall clock — plus a little slack.
     */
    public const REPLAY_TTL_SECONDS = 2 * self::MAX_CLOCK_SKEW_SECONDS + 30;

    private const CACHE_PREFIX = 'nip98:consumed:';

    /**
     * Verify the request's NIP-98 credential and return the pubkey that
     * signed it. The event id is burned on success, so the same credential
     * cannot be presented twice.
     *
     * @throws Nip98Exception
     */
    public static function verify(Request $request): string
    {
        $event = self::decode($request);

        self::assertKind($event);
        self::assertMethod($event, $request);
        self::assertUrl($event, $request);
        self::assertFreshness($event);
        self::assertContentType($request);
        self::assertPayload($event, $request);
        self::assertEventId($event);
        self::assertSignature($event);
        self::consume($event['id']);

        return $event['pubkey'];
    }

    /**
     * Read the `Authorization: Nostr <base64>` header and return the event as
     * a strictly typed array. Anything the signature check would choke on
     * later is rejected here instead, so `invalid_signature` really means
     * "the signature is wrong" and not "the JSON had a float in it".
     *
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: array<int, array<int, string>>, content: string, sig: string}
     *
     * @throws Nip98Exception
     */
    private static function decode(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');

        if ($header === '' || ! Str::startsWith(Str::lower($header), 'nostr ')) {
            throw new Nip98Exception(Nip98Exception::MISSING_AUTHORIZATION);
        }

        $encoded = trim(Str::after($header, ' '));
        $json = base64_decode($encoded, true);

        if ($json === false) {
            throw new Nip98Exception(Nip98Exception::MALFORMED_AUTHORIZATION);
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new Nip98Exception(Nip98Exception::MALFORMED_AUTHORIZATION);
        }

        if (! is_array($decoded)) {
            throw new Nip98Exception(Nip98Exception::MALFORMED_AUTHORIZATION);
        }

        foreach (['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new Nip98Exception(Nip98Exception::MALFORMED_EVENT);
            }
        }

        if (! is_string($decoded['id'])
            || ! is_string($decoded['pubkey'])
            || ! is_string($decoded['content'])
            || ! is_string($decoded['sig'])
            || ! is_int($decoded['created_at'])
            || ! is_int($decoded['kind'])
            || ! is_array($decoded['tags'])
        ) {
            throw new Nip98Exception(Nip98Exception::MALFORMED_EVENT);
        }

        /*
         * NIP-01 knows exactly one spelling for ids, pubkeys and signatures:
         * lowercase hex. Enforcing it is not cosmetic — the event id is
         * computed over the pubkey STRING as delivered, and the Schnorr
         * verifier accepts uppercase hex, so one private key can produce
         * arbitrarily many perfectly valid events that differ only in case.
         * Each of them is a different string, and therefore a different
         * rate-limiter bucket: measured against a live server with a limit of
         * 2/min, requests 4 to 6 sailed through after request 3 had been
         * throttled. That defeats the invoice quota, which is what stops
         * somebody from having the association pay for BTCPay invoices.
         *
         * Rejected rather than lowercased: downcasing would make the verified
         * serialisation differ from the identity we then report and rate-limit
         * on, which is a worse problem than the one it solves.
         */
        if (preg_match('/^[0-9a-f]{64}$/', $decoded['id']) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $decoded['pubkey']) !== 1
            || preg_match('/^[0-9a-f]{128}$/', $decoded['sig']) !== 1
        ) {
            throw new Nip98Exception(Nip98Exception::MALFORMED_EVENT);
        }

        foreach ($decoded['tags'] as $tag) {
            if (! is_array($tag)) {
                throw new Nip98Exception(Nip98Exception::MALFORMED_EVENT);
            }

            foreach ($tag as $value) {
                if (! is_string($value)) {
                    throw new Nip98Exception(Nip98Exception::MALFORMED_EVENT);
                }
            }
        }

        return [
            'id' => $decoded['id'],
            'pubkey' => $decoded['pubkey'],
            'created_at' => $decoded['created_at'],
            'kind' => $decoded['kind'],
            'tags' => array_values($decoded['tags']),
            'content' => $decoded['content'],
            'sig' => $decoded['sig'],
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws Nip98Exception
     */
    private static function assertKind(array $event): void
    {
        if ($event['kind'] !== self::EVENT_KIND) {
            throw new Nip98Exception(Nip98Exception::INVALID_KIND);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws Nip98Exception
     */
    private static function assertMethod(array $event, Request $request): void
    {
        $method = self::tag($event, 'method');

        if ($method === null || Str::upper($method) !== Str::upper($request->method())) {
            throw new Nip98Exception(Nip98Exception::METHOD_MISMATCH);
        }
    }

    /**
     * The `u` tag must be the absolute URL including scheme and host. Without
     * that, an event signed for endpoint A can simply be handed to endpoint B
     * — the client-side signer would have authorised "read my status" and the
     * relaying party would spend it on "create an invoice".
     *
     * @param  array<string, mixed>  $event
     *
     * @throws Nip98Exception
     */
    private static function assertUrl(array $event, Request $request): void
    {
        $url = self::tag($event, 'u');

        if ($url === null || ! hash_equals(self::expectedUrl($request), $url)) {
            throw new Nip98Exception(Nip98Exception::URL_MISMATCH);
        }
    }

    /**
     * The URL this request must have been signed for.
     *
     * Built from config('app.url') plus the request URI, NOT from
     * $request->fullUrl(). fullUrl() derives scheme and host from the Host
     * header, which nothing validates — measured against a live server, an
     * event signed for `https://evil.example/...` was accepted when the same
     * value was sent as the Host header, and so was a variant differing only
     * in port. That empties the host half of the rule, and the host is the
     * one thing an end user can actually check in a NIP-07 signing dialog:
     * a client operator could have his users sign events for HIS domain and
     * spend them here.
     *
     * Only the origin is taken from the configuration; path and query come
     * from the request, so a differing path, a trailing slash or a differing
     * query string still fail — those are part of what was signed.
     *
     * @throws Nip98Exception
     */
    private static function expectedUrl(Request $request): string
    {
        $parts = parse_url((string) config('app.url'));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            throw Nip98Exception::applicationUrlUnreadable();
        }

        $origin = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.$request->getRequestUri();
    }

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws Nip98Exception
     */
    private static function assertFreshness(array $event): void
    {
        if (abs(now()->timestamp - $event['created_at']) > self::MAX_CLOCK_SKEW_SECONDS) {
            throw new Nip98Exception(Nip98Exception::STALE_EVENT);
        }
    }

    /**
     * Does this request carry anything a signature would have to cover?
     *
     * Three sources, because PHP does not put every body in the same place.
     * Under a real SAPI a multipart body has already been parsed into $_POST
     * by the time application code runs, and php://input — and therefore
     * getContent() — is EMPTY. Asking only "is the raw body empty" would
     * therefore report "no input" for a request full of input.
     */
    private static function carriesInput(Request $request): bool
    {
        return $request->getContent() !== ''
            || $request->request->count() > 0
            || $request->allFiles() !== [];
    }

    /**
     * /api/v1 accepts JSON bodies and nothing else.
     *
     * This is the primary fix for the multipart hole: measured against a live
     * server, an event signed for body A was accepted with a completely
     * different multipart body B (HTTP 200, raw body "", $_POST carrying the
     * attacker's values) — the signature then covered user, method and URL,
     * but not one byte of the data. Refusing the content type keeps the
     * request from ever reaching a state where the body cannot be checked.
     *
     * @throws Nip98Exception
     */
    private static function assertContentType(Request $request): void
    {
        if (! self::carriesInput($request)) {
            return;
        }

        $type = Str::lower(trim(Str::before((string) $request->header('Content-Type', ''), ';')));

        /*
         * Both predicates, because a case-insensitive check on its own is not
         * the same question Laravel asks. Request::isJson() matches '/json'
         * and '+json' CASE-SENSITIVELY, so `Content-Type: Application/JSON`
         * passed this guard while Laravel refused to parse the body:
         * measured against a live server, the request answered 200 with the
         * signed bytes in the raw body and an EMPTY input array. The
         * signature bound correctly and the endpoint still saw different data
         * than the user signed — a client operator could suppress a signed
         * field without breaking the binding. Requiring isJson() as well
         * keeps "what was signed" and "what is read" on the same predicate.
         */
        if ($type !== 'application/json' || ! $request->isJson()) {
            throw Nip98Exception::unsupportedContentType();
        }
    }

    /**
     * The `payload` tag binds the signature to the body. It is hashed over
     * the RAW body, never over the parsed one: two different byte strings can
     * parse to the same array, and it is the bytes that the endpoint acts on.
     *
     * The empty-raw-body branch is the backstop behind assertContentType():
     * if input exists but the raw bytes are gone, there is nothing the
     * signature could be checked against, and an unverifiable body is refused
     * rather than waved through. Note that requiring the tag alone would not
     * be enough — sha256("") is a value an attacker can sign just as easily.
     *
     * @param  array<string, mixed>  $event
     *
     * @throws Nip98Exception
     */
    private static function assertPayload(array $event, Request $request): void
    {
        if (! self::carriesInput($request)) {
            return;
        }

        $body = $request->getContent();

        if ($body === '') {
            throw new Nip98Exception(Nip98Exception::UNREADABLE_BODY);
        }

        $payload = self::tag($event, 'payload');

        if ($payload === null || ! hash_equals(hash('sha256', $body), Str::lower($payload))) {
            throw new Nip98Exception(Nip98Exception::PAYLOAD_MISMATCH);
        }
    }

    /**
     * Recompute the event id from the serialised payload, exactly as NIP-01
     * defines it. `Event::verify()` does this too, but it folds the result
     * into the same boolean as the signature check — and a tampered id and a
     * tampered signature are different attacks that deserve different
     * failure paths.
     *
     * @param  array<string, mixed>  $event
     *
     * @throws Nip98Exception
     */
    private static function assertEventId(array $event): void
    {
        $serialized = json_encode(
            [0, $event['pubkey'], $event['created_at'], $event['kind'], $event['tags'], $event['content']],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($serialized === false) {
            throw new Nip98Exception(Nip98Exception::MALFORMED_EVENT);
        }

        // No case folding here: decode() already guarantees lowercase hex.
        if (! hash_equals(hash('sha256', $serialized), $event['id'])) {
            throw new Nip98Exception(Nip98Exception::INVALID_EVENT_ID);
        }
    }

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws Nip98Exception
     */
    private static function assertSignature(array $event): void
    {
        $valid = false;

        try {
            $valid = (new NostrEvent)->verify((object) $event);
        } catch (Throwable) {
            $valid = false;
        }

        if (! $valid) {
            throw new Nip98Exception(Nip98Exception::INVALID_SIGNATURE);
        }
    }

    /**
     * Burn the event id so the same credential cannot be presented twice.
     *
     * `add()` is the atomic put-if-absent of the cache contract, so two
     * concurrent replays cannot both win. A store that cannot answer closes
     * the door (503) instead of waving the request through — a replay guard
     * that disappears when the cache hiccups is not a guard.
     *
     * @throws Nip98Exception
     */
    private static function consume(string $eventId): void
    {
        $configured = config('einundzwanzig.config.api_replay_cache_store');

        /*
         * An empty string is not a store name — it is what dotenv hands back
         * for `API_REPLAY_CACHE_STORE=`, the very line shipped in
         * .env.example. CacheManager::store() falls back to the default store
         * only for null, so without this normalisation the documented
         * "leave empty" configuration made every authenticated /api/v1
         * request answer 503. Measured against a live server before the fix.
         * Belt and braces alongside the `?: null` in the config file.
         */
        $store = is_string($configured) && trim($configured) !== '' ? trim($configured) : null;

        try {
            $fresh = Cache::store($store)
                ->add(self::CACHE_PREFIX.$eventId, true, self::REPLAY_TTL_SECONDS);
        } catch (Throwable) {
            throw Nip98Exception::replayStoreUnavailable();
        }

        if ($fresh !== true) {
            throw new Nip98Exception(Nip98Exception::REPLAYED);
        }
    }

    /**
     * First value of the first tag with the given name, or null.
     *
     * @param  array<string, mixed>  $event
     */
    private static function tag(array $event, string $name): ?string
    {
        foreach ($event['tags'] as $tag) {
            if (($tag[0] ?? null) === $name) {
                return $tag[1] ?? null;
            }
        }

        return null;
    }
}
