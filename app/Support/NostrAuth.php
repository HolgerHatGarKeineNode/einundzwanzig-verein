<?php

namespace App\Support;

use App\Auth\NostrUser;
use App\Models\EinundzwanzigPleb;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use swentel\nostr\Event\Event as NostrEvent;
use swentel\nostr\Key\Key;

class NostrAuth
{
    private const CHALLENGE_SESSION_KEY = 'nostr_login_challenge';

    private const CHALLENGE_EXPIRES_SESSION_KEY = 'nostr_login_challenge_expires_at';

    private const CHALLENGE_TTL_SECONDS = 300;

    private const LOGIN_EVENT_KIND = 22242;

    public static function login(string $pubkey): void
    {
        Auth::guard('nostr')->loginByPubkey($pubkey);
        Session::regenerate();
    }

    /**
     * Generate a fresh NIP-42-style login challenge, persist it to the session,
     * and return it. The frontend embeds this challenge into a kind-22242 event
     * that the Nostr signer must sign before we accept the login.
     */
    public static function issueChallenge(): string
    {
        $challenge = bin2hex(random_bytes(32));

        Session::put(self::CHALLENGE_SESSION_KEY, $challenge);
        Session::put(self::CHALLENGE_EXPIRES_SESSION_KEY, now()->addSeconds(self::CHALLENGE_TTL_SECONDS)->timestamp);

        return $challenge;
    }

    /**
     * Return the active session challenge if still valid, otherwise issue a
     * fresh one. Keeps the sidebar + navbar auth-button mounts in sync —
     * without this, the second mount would overwrite the first in the
     * session and leave the first instance's rendered data-attribute
     * pointing at a stale challenge.
     */
    public static function currentOrIssueChallenge(): string
    {
        $existing = Session::get(self::CHALLENGE_SESSION_KEY);
        $expiresAt = (int) Session::get(self::CHALLENGE_EXPIRES_SESSION_KEY, 0);

        if (is_string($existing) && $existing !== '' && $expiresAt >= now()->timestamp) {
            return $existing;
        }

        return self::issueChallenge();
    }

    /**
     * Verify a signed NIP-42-style login event and log the holder of the pubkey in.
     *
     * Idempotent across concurrent Livewire listeners: once the challenge has been
     * consumed, a second call with the same event still succeeds as long as the
     * caller's session is already authenticated with the matching pubkey.
     *
     * @return string the verified pubkey
     */
    public static function loginWithSignedEvent(mixed $signedEvent): string
    {
        $pubkey = self::verifySignedEvent($signedEvent);

        self::ensurePleb($pubkey);

        if (! self::check() || self::pubkey() !== $pubkey) {
            self::login($pubkey);
        }

        return $pubkey;
    }

    /**
     * Create the member record for a pubkey whose signature was just verified.
     *
     * This used to be a side effect of `GET /api/nostr/profile/{key}`, which the
     * login frontend happened to call before dispatching. That was an
     * unauthenticated GET writing to the member table: any hex string created a
     * row that fed the board overview and the CSV export. The record now comes
     * into being here — after `verifySignedEvent()` has proven that the caller
     * holds the private key — so nobody can create a member record for a pubkey
     * they do not own.
     *
     * Idempotent by `pubkey` (uniquely indexed), and it writes nothing but the
     * identity: `association_status` is left to the column default DEFAULT(1),
     * because only a paid fee may raise it.
     */
    private static function ensurePleb(string $pubkey): void
    {
        try {
            EinundzwanzigPleb::query()->firstOrCreate(
                ['pubkey' => $pubkey],
                ['npub' => (new Key)->convertPublicKeyToBech32($pubkey)]
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent login won the race; the unique index on `pubkey`
            // settled it and the record we wanted exists either way.
        }
    }

    /**
     * Verify the cryptographic signature of a kind-22242 event and that its
     * challenge tag matches the value stored on this session. Consumes the
     * stored challenge on success so it cannot be reused.
     *
     * @return string the verified pubkey
     */
    public static function verifySignedEvent(mixed $signedEvent): string
    {
        if (! is_array($signedEvent)) {
            throw ValidationException::withMessages(['nostr' => __('auth.failed')]);
        }

        foreach (['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'] as $field) {
            if (! array_key_exists($field, $signedEvent)) {
                throw ValidationException::withMessages(['nostr' => __('auth.failed')]);
            }
        }

        if ((int) $signedEvent['kind'] !== self::LOGIN_EVENT_KIND) {
            throw ValidationException::withMessages(['nostr' => __('auth.failed')]);
        }

        $createdAt = (int) $signedEvent['created_at'];
        if (abs(now()->timestamp - $createdAt) > self::CHALLENGE_TTL_SECONDS) {
            throw ValidationException::withMessages(['nostr' => __('auth.failed')]);
        }

        $challengeFromEvent = null;
        foreach ($signedEvent['tags'] as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'challenge') {
                $challengeFromEvent = (string) ($tag[1] ?? '');
                break;
            }
        }

        if ($challengeFromEvent === null || $challengeFromEvent === '') {
            throw ValidationException::withMessages(['nostr' => __('auth.failed')]);
        }

        $expectedChallenge = Session::get(self::CHALLENGE_SESSION_KEY);
        $expiresAt = (int) Session::get(self::CHALLENGE_EXPIRES_SESSION_KEY, 0);
        $challengeMatchesSession = is_string($expectedChallenge)
            && $expectedChallenge !== ''
            && $expiresAt >= now()->timestamp
            && hash_equals($expectedChallenge, $challengeFromEvent);

        // swentel's verify() accepts an object directly, so we pass a normalized
        // stdClass and skip an unnecessary json_encode + json_decode round-trip.
        // Casts here enforce the property types swentel checks against.
        $normalizedEvent = (object) [
            'id' => (string) $signedEvent['id'],
            'pubkey' => (string) $signedEvent['pubkey'],
            'created_at' => $createdAt,
            'kind' => self::LOGIN_EVENT_KIND,
            'tags' => $signedEvent['tags'],
            'content' => (string) $signedEvent['content'],
            'sig' => (string) $signedEvent['sig'],
        ];

        $sigValid = false;
        try {
            $sigValid = (new NostrEvent)->verify($normalizedEvent);
        } catch (\Throwable) {
            $sigValid = false;
        }

        if (! $sigValid) {
            throw ValidationException::withMessages(['nostr' => __('auth.failed')]);
        }

        $eventPubkey = (string) $signedEvent['pubkey'];

        if ($challengeMatchesSession) {
            Session::forget([self::CHALLENGE_SESSION_KEY, self::CHALLENGE_EXPIRES_SESSION_KEY]);

            return $eventPubkey;
        }

        // Idempotent path: the challenge has already been consumed (e.g. a
        // sibling Livewire listener processed the same event microseconds
        // earlier). Only accept if the current session is already
        // authenticated with this exact pubkey.
        if (self::check() && self::pubkey() === $eventPubkey) {
            return $eventPubkey;
        }

        throw ValidationException::withMessages(['nostr' => __('auth.failed')]);
    }

    public static function logout(): void
    {
        if (Auth::guard('nostr')->check()) {
            Auth::guard('nostr')->logout();
            Session::flush();
        }
    }

    public static function user(): ?NostrUser
    {
        return Auth::guard('nostr')->user();
    }

    public static function check(): bool
    {
        return Auth::guard('nostr')->check();
    }

    public static function pubkey(): ?string
    {
        return self::user()?->getPubkey();
    }
}
