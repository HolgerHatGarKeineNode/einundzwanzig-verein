<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * The IP contradiction this phase had to settle: SecurityMonitor read
 * X-Forwarded-For unconditionally, the rate limiters counted $request->ip(),
 * and trustProxies() was never called. Whoever wrote the header decided what
 * got logged, while a different address decided what got blocked.
 *
 * Production has no proxy in front (Forge server, nginx and PHP-FPM on the
 * same host, no CDN), so the header is attacker-controlled and the answer is
 * to trust nobody by default. This test runs through the real global
 * middleware stack — TrustProxies included — and therefore fails if that
 * default is ever quietly loosened to '*'.
 */

beforeEach(function () {
    Route::get('/_observed-ip', fn (Request $request) => ['ip' => $request->ip()]);
});

it('ignores a forged X-Forwarded-For', function () {
    $this->call('GET', '/_observed-ip', server: [
        'REMOTE_ADDR' => '198.51.100.7',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        'HTTP_ACCEPT' => 'application/json',
    ])->assertSuccessful()->assertJson(['ip' => '198.51.100.7']);
});

it('honours a forwarded address once the proxy is configured', function () {
    /*
     * The switch has to actually work, not merely exist. It used to be read
     * as env('TRUSTED_PROXIES') inside the withMiddleware() closure — which
     * runs BEFORE Laravel loads the .env, so the value was always empty.
     * Measured against `php -S` with TRUSTED_PROXIES=127.0.0.1 in the .env:
     * the forged header stayed ignored. Harmless while "trust none" is the
     * intent, and a trap on the day a CDN appears: the operator sets the
     * documented variable, nothing happens, every IP bucket collapses onto
     * the proxy and fullUrl() reports http:// — which would answer 401 to
     * every NIP-98 request.
     *
     * The configuration now lives in config/trustedproxy.php, which is
     * evaluated after the .env and read by TrustProxies at request time.
     */
    config(['trustedproxy.proxies' => ['198.51.100.7']]);

    $this->call('GET', '/_observed-ip', server: [
        'REMOTE_ADDR' => '198.51.100.7',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        'HTTP_ACCEPT' => 'application/json',
    ])->assertSuccessful()->assertJson(['ip' => '203.0.113.50']);
});

it('ignores a forwarded address sent by a host that is not the configured proxy', function () {
    config(['trustedproxy.proxies' => ['10.9.9.9']]);

    $this->call('GET', '/_observed-ip', server: [
        'REMOTE_ADDR' => '198.51.100.7',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        'HTTP_ACCEPT' => 'application/json',
    ])->assertSuccessful()->assertJson(['ip' => '198.51.100.7']);
});

it('ignores a forged X-Forwarded-Proto', function () {
    // Same trust boundary, and it matters beyond logging: NIP-98 compares its
    // `u` tag against $request->fullUrl(), whose scheme comes from exactly
    // this header when a proxy is trusted.
    Route::get('/_observed-scheme', fn (Request $request) => ['secure' => $request->isSecure()]);

    $this->call('GET', '/_observed-scheme', server: [
        'REMOTE_ADDR' => '198.51.100.7',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_ACCEPT' => 'application/json',
    ])->assertSuccessful()->assertJson(['secure' => false]);
});
