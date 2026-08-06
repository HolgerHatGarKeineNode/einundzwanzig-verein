<?php

namespace App\Support;

use Illuminate\Http\Request;
use swentel\nostr\Key\Key;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The two identities an /api/v1 request carries, and the rule that keeps them
 * apart.
 *
 * The client key names the APPLICATION: quota, revocation, attribution in the
 * log. It never authorises an operation on a member record.
 *
 * The NIP-98 pubkey names the END USER and is the only admissible answer to
 * "whose membership is this about". Because it comes out of a signature the
 * calling application cannot produce (the private key stays with the user's
 * NIP-07/NIP-46 signer), even a compromised third-party operator can withhold
 * requests but not forge them.
 *
 * Endpoints in P4 MUST read the subject from `pubkey()`. That is the rule;
 * `guardClaimedSubject()` is a backstop, not a substitute — see its own
 * docblock for the precise and deliberately narrow scope it covers.
 */
class ApiIdentity
{
    public const PUBKEY_ATTRIBUTE = 'nip98_pubkey';

    public const CLIENT_ATTRIBUTE = 'api_client_name';

    /**
     * Top-level request fields and route parameters that name a subject.
     *
     * `key` is on the list because it is the spelling this repository already
     * uses for a pubkey in a route (routes/api.php: /nostr/profile/{key}).
     *
     * @var array<int, string>
     */
    private const CLAIM_KEYS = ['pubkey', 'npub', 'key'];

    /**
     * The verified pubkey of the end user. Only callable behind VerifyNip98;
     * its absence is a wiring mistake, and the safe answer to a wiring
     * mistake is to refuse.
     */
    public static function pubkey(Request $request): string
    {
        $pubkey = self::pubkeyOrNull($request);

        if ($pubkey === null) {
            throw new HttpException(401, 'Unauthorized.');
        }

        return $pubkey;
    }

    public static function pubkeyOrNull(Request $request): ?string
    {
        $pubkey = $request->attributes->get(self::PUBKEY_ATTRIBUTE);

        return is_string($pubkey) && $pubkey !== '' ? $pubkey : null;
    }

    /**
     * The resolved name of the calling application, e.g. "einundzwanzig-group".
     * Never the key itself.
     */
    public static function client(Request $request): ?string
    {
        $name = $request->attributes->get(self::CLIENT_ATTRIBUTE);

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Reject a request that claims to act for somebody else.
     *
     * A null claim means the request named no subject at all, which is the
     * normal case: the subject is then taken from the signature.
     */
    public static function assertOwns(Request $request, ?string $claimed): void
    {
        if ($claimed === null || $claimed === '') {
            return;
        }

        $pubkey = self::pubkey($request);

        $expected = str_starts_with($claimed, 'npub1')
            ? self::npub($pubkey)
            : $pubkey;

        if ($expected === null || ! hash_equals($expected, strtolower($claimed))) {
            throw new AccessDeniedHttpException('Forbidden.');
        }
    }

    /**
     * Backstop against the most common way of naming somebody else: a
     * TOP-LEVEL route parameter or input field called `pubkey`, `npub` or
     * `key`. Applied by VerifyNip98 to every /api/v1 request.
     *
     * What it does NOT do, stated plainly because a guard that is believed to
     * be complete is worse than one known to be partial: it does not look
     * inside nested structures, and it does not know any other spelling.
     * `{"member":{"pubkey":"…"}}`, `public_key`, `target_pubkey` and friends
     * pass through untouched. Making it recursive and name-guessing would
     * trade a known gap for false 403s on unrelated fields.
     *
     * The rule that actually holds is the one above: an endpoint takes its
     * subject from pubkey(). Where an endpoint has a legitimate reason to
     * accept a subject under some other name, it calls assertOwns() itself.
     */
    public static function guardClaimedSubject(Request $request): void
    {
        foreach (self::CLAIM_KEYS as $key) {
            $routeClaim = $request->route($key);

            if (is_string($routeClaim)) {
                self::assertOwns($request, $routeClaim);
            }

            $inputClaim = $request->input($key);

            if (is_string($inputClaim)) {
                self::assertOwns($request, $inputClaim);
            }
        }
    }

    private static function npub(string $pubkey): ?string
    {
        try {
            return (new Key)->convertPublicKeyToBech32($pubkey);
        } catch (Throwable) {
            return null;
        }
    }
}
