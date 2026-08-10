<?php

namespace App\Support;

/**
 * Where a payer may be sent after the BTCPay checkout — and the check that
 * decides it.
 *
 * `POST /api/v1/membership/payments/{year}/invoice` lets a client name a
 * return address so that the payer lands back in that client's onboarding flow
 * instead of on the association's profile page. The value travels into
 * `checkout.redirectURL` of the BTCPay payload, which means a third party's
 * page will send somebody's browser there, with the association's own domain
 * as the referrer that made it look trustworthy. A freely settable value is
 * therefore an open redirect, and the mitigation is an allowlist that lives on
 * the server (`einundzwanzig.config.invoice_return_urls`) and nowhere near the
 * request.
 *
 * FAIL-CLOSED IN BOTH DIRECTIONS. An address that is not on the list is
 * refused (422 through the form request) rather than quietly replaced by the
 * default, because a silent fallback makes an attack attempt and a
 * misconfigured deployment look identical — both "work", and neither is ever
 * noticed. An allowlist ENTRY that does not parse is dropped rather than
 * matched loosely, so a typo in the environment can only ever allow less, and
 * an empty or absent list allows nothing at all.
 *
 * WHY THE COMPARISON IS STRUCTURAL and not `str_starts_with()` on the raw
 * string — every one of these is a real bypass of a prefix check:
 *
 *  - `https://verein.einundzwanzig.space.angreifer.tld/` starts with an
 *    allowed host and is a different domain. Hosts are compared for EQUALITY,
 *    never as a prefix.
 *  - `https://verein.einundzwanzig.space@angreifer.tld/` is a URL whose host
 *    is `angreifer.tld`; the allowed-looking part is a userinfo field. Any
 *    credentials at all are refused outright.
 *  - `HTTPS://VEREIN.EINUNDZWANZIG.SPACE/x` differs byte for byte and is the
 *    same address. Scheme and host are lowercased before comparing.
 *  - `https://verein.einundzwanzig.space:443/x` and the same URL without the
 *    port are one address; `:8443` is another. The default port for the scheme
 *    is filled in, so both spellings collapse and a foreign port does not.
 *  - `https://%76erein.einundzwanzig.space/` is percent-encoded past a naive
 *    comparison. Host characters are restricted to what a hostname may
 *    contain, so this is refused rather than decoded — decoding would be a
 *    second parser to get wrong.
 *  - `https://ok.tld\@angreifer.tld/` exploits the disagreement between PHP's
 *    parser and a browser's. Backslashes, whitespace and control characters
 *    are refused before parsing.
 */
class InvoiceReturnUrl
{
    /**
     * Enough for any real return address, short enough that nothing pathological
     * reaches the parser.
     */
    public const MAX_LENGTH = 2048;

    /**
     * The only schemes a return address may use.
     *
     * `http` is on the list for local development, where the client runs on
     * `http://localhost`. It buys nothing for an attacker that `https` would
     * not: the allowlist decides the destination, and the scheme is only ever
     * compared, never chosen.
     *
     * @var array<string, int>
     */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Is this address one the association is willing to send a payer to?
     *
     * The only public question, and the only one the form request asks.
     */
    public static function isAllowed(string $candidate): bool
    {
        $normalized = self::normalize($candidate);

        if ($normalized === null) {
            return false;
        }

        foreach (self::allowed() as $entry) {
            $allowed = self::normalize($entry);

            if ($allowed !== null && $allowed === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured allowlist, as written.
     *
     * Not normalized here on purpose: `isAllowed()` normalizes both sides with
     * the same function, so there is exactly one definition of what two
     * addresses being equal means. A pre-normalized list would be a second one.
     *
     * @return list<string>
     */
    public static function allowed(): array
    {
        $configured = config('einundzwanzig.config.invoice_return_urls', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter($configured, 'is_string'));
    }

    /**
     * One address reduced to the parts that decide identity, or null when it
     * is not an address this application will handle.
     *
     * Returns a string rather than a tuple so that comparing two of them is a
     * single `===` that cannot be got subtly wrong at the call site. The
     * fragment is part of it: it is meaningless to BTCPay but visible to the
     * client, and a value that differs must not be treated as the same
     * address.
     */
    private static function normalize(string $url): ?string
    {
        /*
         * CONTROL CHARACTERS ARE CHECKED BEFORE trim(), NOT AFTER.
         *
         * `trim()`'s default charlist is " \t\n\r\0\x0B" — it eats a NUL, a
         * newline and a tab sitting at either end. A check placed after it can
         * therefore never see one, and the value that actually travels into
         * `checkout.redirectURL` is not this trimmed copy but the raw string
         * from `validated()`: Laravel's TrimStrings middleware strips ordinary
         * whitespace from request input but leaves a NUL in place. So an
         * address ending in a NUL used to pass this check and reach BTCPay
         * with the byte still attached.
         *
         * No exploit followed from it — scheme, host, port, path, query and
         * fragment all still had to equal an allowed entry, leaving the NUL
         * itself as the only attacker-controlled part. It is refused anyway,
         * because "harmless as long as five other checks hold" is not a
         * property worth depending on.
         */
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        // Only ordinary spaces can be left at the edges now; everything else
        // trim() would have removed was refused above.
        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        /*
         * Before parsing, not after. A backslash or a raw space is where PHP's
         * parser and a browser's start to disagree about what the host is, and
         * that disagreement is the whole trick behind
         * `https://ok.tld\@angreifer.tld/`. Nothing legitimate needs either of
         * them unescaped.
         */
        if (preg_match('/[\\\\\s]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        // Any userinfo at all. `https://allowed.tld@evil.tld/` parses to host
        // `evil.tld` and would be caught by the host comparison anyway — but a
        // return address has no business carrying credentials, and refusing
        // the shape outright is one fewer thing that has to stay true.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! array_key_exists($scheme, self::DEFAULT_PORTS)) {
            return null;
        }

        // Trailing dot: `verein.einundzwanzig.space.` and
        // `verein.einundzwanzig.space` resolve to the same name.
        $host = rtrim(strtolower((string) ($parts['host'] ?? '')), '.');

        /*
         * What a hostname may consist of, and nothing else. An
         * internationalised domain must be given in its punycode form
         * (`xn--…`), which matches this and is the spelling that ends up on
         * the wire anyway. Percent-encoding is refused rather than decoded:
         * decoding here would be a second URL parser, and two parsers that
         * disagree is the bug class this whole class exists to avoid.
         */
        if (preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
            return null;
        }

        $port = $parts['port'] ?? self::DEFAULT_PORTS[$scheme];

        // An empty path and `/` are the same address to every server there is.
        $path = (string) ($parts['path'] ?? '');
        $path = $path === '' ? '/' : $path;

        return implode("\n", [
            $scheme,
            $host,
            (string) $port,
            $path,
            (string) ($parts['query'] ?? ''),
            (string) ($parts['fragment'] ?? ''),
        ]);
    }
}
