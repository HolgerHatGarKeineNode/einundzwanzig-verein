<?php

use Laravel\Nightwatch\Facades\Nightwatch;
use Sentry\SentrySdk;

/*
 * The test process must not inherit a live endpoint or a signing key from the
 * developer's .env.
 *
 * Measured on 2026-11-14 at ea92b25: a green run of this suite made 369
 * connection attempts to the error tracker's host and 5 to the Nightwatch
 * agent, because phpunit.xml overrode neither variable. Nothing inside the
 * process could have noticed — both SDKs bring their own HTTP client, so
 * Http::preventStrayRequests() never sees them. The same .env also carries the
 * association's signing key (NOSTR_P) and a relay address (NOSTR_SPACE_URL).
 *
 * Every case reads the value the application actually works with, not the
 * environment variable: what counts is that the SDK has no destination and the
 * signer has no key, whichever variable they would have come from.
 *
 * Mutation M0a: delete the SENTRY_LARAVEL_DSN line from phpunit.xml. On a
 * machine whose .env sets that variable, the first case goes red. On a machine
 * without it the case is green either way — it then guards nothing, which is
 * exactly the state a fresh checkout is in.
 */

it('gives the error tracker no destination to send to', function () {
    /*
     * Compared down to a boolean on purpose: a failing toBeNull() would export
     * the DSN object, key included, into the test output.
     */
    $clientHasDestination = SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn() !== null;

    expect(config('sentry.dsn'))->toBeEmpty()
        ->and($clientHasDestination)->toBeFalse();
});

it('keeps Nightwatch from collecting and sending', function () {
    expect(Nightwatch::enabled())->toBeFalse();
});

it('has no signing key and no relay to publish to', function (string $key) {
    expect(config($key))->toBeEmpty();
})->with([
    'signing key' => 'services.nostr',
    'notification key' => 'services.nostr_notification_key',
    'publishing relay' => 'services.relay',
    'profile relays' => 'einundzwanzig.config.profile_relays',
    'chat space relay' => 'group.space_url',
]);
