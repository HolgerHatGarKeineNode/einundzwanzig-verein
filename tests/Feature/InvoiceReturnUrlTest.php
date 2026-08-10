<?php

use App\Support\InvoiceReturnUrl;

/*
 * The allowlist that decides where a payer may be sent after the BTCPay
 * checkout.
 *
 * Worth its own file, separate from the endpoint that uses it, because this is
 * the fail-closed boundary: the value it approves ends up in
 * `checkout.redirectURL` at BTCPay, and a BTCPay page then sends somebody's
 * browser there with the association's domain as the referrer that made it
 * look trustworthy. Every case below is a way a prefix comparison on the raw
 * string says yes to an address a browser resolves somewhere else.
 */

const RETURN_URL_ALLOWED = 'https://einundzwanzig.group/verein/beitritt';

beforeEach(function () {
    config(['einundzwanzig.config.invoice_return_urls' => [RETURN_URL_ALLOWED]]);
});

it('accepts the configured address', function () {
    expect(InvoiceReturnUrl::isAllowed(RETURN_URL_ALLOWED))->toBeTrue();
});

it('accepts a different spelling of the same address', function (string $candidate) {
    /*
     * The comparison is structural, so the spellings a browser considers
     * identical must be accepted — otherwise the allowlist would refuse
     * correct clients and push integrators towards asking for a looser check.
     */
    expect(InvoiceReturnUrl::isAllowed($candidate))->toBeTrue();
})->with([
    'uppercase scheme' => 'HTTPS://einundzwanzig.group/verein/beitritt',
    'uppercase host' => 'https://EINUNDZWANZIG.GROUP/verein/beitritt',
    'explicit default port' => 'https://einundzwanzig.group:443/verein/beitritt',
    'fully qualified host with a trailing dot' => 'https://einundzwanzig.group./verein/beitritt',
    'surrounding whitespace' => '  https://einundzwanzig.group/verein/beitritt  ',
]);

it('refuses an address that only looks like the allowed one', function (string $candidate) {
    expect(InvoiceReturnUrl::isAllowed($candidate))->toBeFalse();
})->with([
    /*
     * The suffix attack, and the reason hosts are compared for EQUALITY. This
     * string starts with the allowed origin and is a domain the attacker
     * controls; `str_starts_with()` on the raw URL waves it through.
     */
    'host with the allowed one as a prefix' => 'https://einundzwanzig.group.angreifer.tld/verein/beitritt',
    'host with the allowed one as a subdomain-looking suffix' => 'https://einundzwanzig.groupx/verein/beitritt',

    /*
     * The userinfo trick. PHP and a browser agree here — the host is
     * `angreifer.tld` — but a human reading the string does not, and neither
     * does a prefix check.
     */
    'allowed host as userinfo' => 'https://einundzwanzig.group@angreifer.tld/verein/beitritt',
    'allowed host as userinfo with a password' => 'https://einundzwanzig.group:x@angreifer.tld/verein/beitritt',

    // Where PHP's parser and a browser's start to disagree about the host.
    'backslash before the at sign' => 'https://einundzwanzig.group\\@angreifer.tld/verein/beitritt',
    'backslash separator' => 'https:\\\\einundzwanzig.group\\verein\\beitritt',

    // Percent-encoding is refused rather than decoded: decoding here would be
    // a second URL parser, and two parsers that disagree is the bug class.
    'percent-encoded host' => 'https://%65inundzwanzig.group/verein/beitritt',

    'a different port' => 'https://einundzwanzig.group:8443/verein/beitritt',
    'a different scheme' => 'http://einundzwanzig.group/verein/beitritt',
    'a scheme that is not http at all' => 'javascript://einundzwanzig.group/verein/beitritt',
    'no scheme at all' => '//einundzwanzig.group/verein/beitritt',

    'a different path' => 'https://einundzwanzig.group/verein/beitritt/../../admin',
    'a longer path' => 'https://einundzwanzig.group/verein/beitritt/extra',
    'a shorter path' => 'https://einundzwanzig.group/verein',
    'the bare origin' => 'https://einundzwanzig.group',

    // Not part of the configured address, and visible to whoever lands there.
    'an added query' => 'https://einundzwanzig.group/verein/beitritt?next=https://angreifer.tld',
    'an added fragment' => 'https://einundzwanzig.group/verein/beitritt#angreifer',

    'an ip literal' => 'https://127.0.0.1/verein/beitritt',
    'a newline in the middle' => "https://einundzwanzig.group/verein/beitritt\nX-Evil: 1",
    'empty' => '',
    'not a url at all' => 'beitritt',
]);

it('refuses everything when nothing is configured', function (mixed $configured) {
    /*
     * FAIL-CLOSED. "Nothing configured" must never mean "anything goes" —
     * the same rule `api_client_keys` follows. A deployment that forgot the
     * environment variable refuses every return address, which is noisy and
     * therefore fixable; the opposite is silent and is an open redirect.
     */
    config(['einundzwanzig.config.invoice_return_urls' => $configured]);

    expect(InvoiceReturnUrl::isAllowed(RETURN_URL_ALLOWED))->toBeFalse();
})->with([
    'an empty list' => [[]],
    'null' => null,
    'a string instead of a list' => RETURN_URL_ALLOWED,
    'a list of non-strings' => [[42, ['nested']]],
]);

it('ignores an allowlist entry that does not parse instead of matching it loosely', function () {
    /*
     * A typo in the environment can only ever allow LESS. The broken entry is
     * dropped; the sound one next to it keeps working, so a bad line does not
     * take the whole configuration down either.
     */
    config(['einundzwanzig.config.invoice_return_urls' => [
        'not a url',
        '',
        'ftp://einundzwanzig.group/verein/beitritt',
        RETURN_URL_ALLOWED,
    ]]);

    expect(InvoiceReturnUrl::isAllowed(RETURN_URL_ALLOWED))->toBeTrue()
        ->and(InvoiceReturnUrl::isAllowed('not a url'))->toBeFalse()
        ->and(InvoiceReturnUrl::isAllowed('ftp://einundzwanzig.group/verein/beitritt'))->toBeFalse();
});

it('accepts an allowed address that carries a query, exactly as configured', function () {
    config(['einundzwanzig.config.invoice_return_urls' => [
        'https://einundzwanzig.group/verein?schritt=zahlung',
    ]]);

    expect(InvoiceReturnUrl::isAllowed('https://einundzwanzig.group/verein?schritt=zahlung'))->toBeTrue()
        // Not the same address: the query is part of what was allowed.
        ->and(InvoiceReturnUrl::isAllowed('https://einundzwanzig.group/verein?schritt=fertig'))->toBeFalse()
        ->and(InvoiceReturnUrl::isAllowed('https://einundzwanzig.group/verein'))->toBeFalse();
});

it('treats an empty path and a bare slash as the same address', function () {
    config(['einundzwanzig.config.invoice_return_urls' => ['https://einundzwanzig.group']]);

    expect(InvoiceReturnUrl::isAllowed('https://einundzwanzig.group'))->toBeTrue()
        ->and(InvoiceReturnUrl::isAllowed('https://einundzwanzig.group/'))->toBeTrue();
});

it('refuses an address longer than the limit before parsing it', function () {
    $long = 'https://einundzwanzig.group/verein/beitritt?x='.str_repeat('a', InvoiceReturnUrl::MAX_LENGTH);

    expect(InvoiceReturnUrl::isAllowed($long))->toBeFalse();
});

it('refuses a control character at the edge, where trim() would have hidden it', function (string $candidate) {
    /*
     * The regression this pins: the control-character check used to run AFTER
     * `trim()`, whose default charlist is " \t\n\r\0\x0B". A NUL or a newline
     * sitting at either end was therefore removed before anything looked for
     * it — and what travels on to BTCPay is not this trimmed copy but the raw
     * request value, which Laravel's TrimStrings middleware leaves a NUL in.
     *
     * Each case below is byte-identical to the allowed address once trimmed,
     * so a check in the old order says yes to every one of them.
     */
    expect(InvoiceReturnUrl::isAllowed($candidate))->toBeFalse();
})->with([
    'trailing NUL' => RETURN_URL_ALLOWED."\0",
    'leading NUL' => "\0".RETURN_URL_ALLOWED,
    'trailing newline' => RETURN_URL_ALLOWED."\n",
    'leading carriage return' => "\r".RETURN_URL_ALLOWED,
    'trailing tab' => RETURN_URL_ALLOWED."\t",
    'trailing vertical tab' => RETURN_URL_ALLOWED."\x0B",
]);

it('allows a localhost address when it is the one configured', function () {
    /*
     * `http` is on the scheme list for exactly this: a client developed
     * locally. It buys an attacker nothing — the allowlist decides the
     * destination, and the scheme is only ever compared, never chosen.
     */
    config(['einundzwanzig.config.invoice_return_urls' => ['http://localhost:5173/verein']]);

    expect(InvoiceReturnUrl::isAllowed('http://localhost:5173/verein'))->toBeTrue()
        ->and(InvoiceReturnUrl::isAllowed('http://localhost/verein'))->toBeFalse()
        ->and(InvoiceReturnUrl::isAllowed('https://localhost:5173/verein'))->toBeFalse();
});
