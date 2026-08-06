<?php

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

/*
 * The Host header allowlist configured in bootstrap/app.php.
 *
 * The middleware itself cannot run here — TrustHosts::shouldSpecifyTrustedHosts()
 * switches it off under APP_ENV=local and in tests. So these tests take the
 * REAL patterns the production configuration produces and hand them to the
 * REAL Symfony mechanism (Request::setTrustedHosts(), which wraps each entry
 * as '{pattern}i' and matches it against the host). Nothing here re-implements
 * the check, and nothing here is a hand-written copy of the allowlist: if the
 * closure in bootstrap/app.php changes, these tests change with it.
 */

/**
 * @return array<int, string>
 */
function trustedHostPatterns(): array
{
    return (new TrustHosts(app()))->hosts();
}

beforeEach(function () {
    config(['app.url' => 'https://verein.einundzwanzig.space']);
});

afterEach(function () {
    // Global static state on the Request class; leaking it would make every
    // later test answer 400 for its own host.
    Request::setTrustedHosts([]);
});

function hostIsTrusted(string $host): bool
{
    try {
        Request::create('http://'.$host.'/up')->getHost();

        return true;
    } catch (SuspiciousOperationException) {
        return false;
    }
}

it('anchors every host pattern', function (string $host, bool $trusted) {
    Request::setTrustedHosts(trustedHostPatterns());

    expect(hostIsTrusted($host))->toBe($trusted);
})->with([
    // Symfony applies each entry as an UNANCHORED regex. With the bare
    // hostname these three were measured at HTTP 200 against a live server
    // under APP_ENV=production — the first of them an attacker-controlled
    // domain that merely starts with ours.
    'the configured host' => ['verein.einundzwanzig.space', true],
    'a domain that merely starts with it' => ['verein.einundzwanzig.space.evil.example', false],
    'a domain that merely ends with it' => ['xxverein.einundzwanzig.space', false],
    'a subdomain, since subdomains are off' => ['www.verein.einundzwanzig.space', false],
    'an unrelated domain' => ['evil.example', false],
    // The machine itself. Without these entries the allowlist locked out
    // every request that does not use the public DNS name.
    'localhost' => ['localhost', true],
    'the loopback address' => ['127.0.0.1', true],
]);

it('lists the IPv6 loopback as well', function () {
    // Not exercised through Request::create(), which does not round-trip a
    // bracketed IPv6 authority; asserted on the pattern instead, applied
    // exactly as Symfony applies it.
    $matches = collect(trustedHostPatterns())
        ->contains(fn (string $pattern): bool => preg_match('{'.$pattern.'}i', '[::1]') === 1);

    expect($matches)->toBeTrue();
});

it('keeps the health check reachable from the machine itself', function () {
    /*
     * `health: '/up'` is registered in bootstrap/app.php. A monitoring ping
     * rarely uses the public DNS name, so an allowlist without localhost
     * would have answered 400 to every health check from the moment of the
     * deploy — measured against a live server under APP_ENV=production.
     */
    Request::setTrustedHosts(trustedHostPatterns());

    $this->get('http://127.0.0.1/up')->assertSuccessful();
    $this->get('http://localhost/up')->assertSuccessful();
});

it('still refuses a foreign host', function () {
    // The other direction of the same allowlist: closing the localhost gap
    // must not open the door generally.
    Request::setTrustedHosts(trustedHostPatterns());

    $this->get('http://evil.example/up')->assertStatus(400);
    $this->get('http://verein.einundzwanzig.space.evil.example/up')->assertStatus(400);
});
