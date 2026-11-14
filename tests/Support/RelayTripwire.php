<?php

namespace Tests\Support;

use App\Services\Nostr\RelayTransport;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Assert;

/**
 * Keeps a test from reaching a relay, and from hiding that it tried.
 *
 * Three layers, wired in tests/Pest.php:
 *
 * 1. arm() binds RefusingRelayTransport, so code that goes through the seam
 *    and was not given a fake throws.
 * 2. tests/Support/phrity-net-socket-shadow.php defines
 *    Phrity\Net\stream_socket_client(), which the vendor WebSocket client
 *    resolves before the built-in function. Code that bypasses the seam — the
 *    existing call sites, or the vendor's NIP-17 path with its hard-wired
 *    public relays — is stopped at the socket.
 * 3. Both layers record the attempt here, and assertNothingAttempted() runs
 *    after every test. This is the layer that matters: phrity's
 *    Client::connect() catches every Throwable and rethrows its own
 *    exception, swentel's Request::send() catches that and returns an error
 *    array, and NostrFetcherTrait catches whatever is left. A refusal alone
 *    ends up as "no data", and the test stays green.
 *
 * tests/Feature/RelayStrayConnectionTest.php proves each layer by its effect.
 */
final class RelayTripwire
{
    /** @var array<int, array{layer: string, operation: string, target: string}> */
    private static array $attempts = [];

    private static bool $loopbackAllowed = false;

    /**
     * Start a test with an empty ledger, loopback closed and the refusing
     * transport bound.
     */
    public static function arm(Application $app): void
    {
        self::reset();

        $app->instance(RelayTransport::class, new RefusingRelayTransport);
    }

    /**
     * The part of arm() that needs no application, for tests/Unit.
     */
    public static function reset(): void
    {
        self::$attempts = [];
        self::$loopbackAllowed = false;
    }

    /**
     * Let this test open sockets to 127.0.0.0/8, ::1 and localhost — for a
     * relay the test started itself. Every other address stays refused.
     */
    public static function allowLoopback(): void
    {
        self::$loopbackAllowed = true;
    }

    public static function record(string $layer, string $operation, string $target): void
    {
        self::$attempts[] = ['layer' => $layer, 'operation' => $operation, 'target' => $target];
    }

    /**
     * Hand the recorded attempts to a test that provoked them on purpose, and
     * clear the ledger so the check after the test passes.
     *
     * @return array<int, array{layer: string, operation: string, target: string}>
     */
    public static function takeAttempts(): array
    {
        $attempts = self::$attempts;
        self::$attempts = [];

        return $attempts;
    }

    /**
     * Fails only when there is something to report, and counts as no
     * assertion otherwise. It runs after every test: an assertion here would
     * give each of them one for free, and PHPUnit could no longer flag a test
     * that asserts nothing — nor would `->throwsNoExceptions()` still hold.
     */
    public static function assertNothingAttempted(): void
    {
        $attempts = self::takeAttempts();

        if ($attempts === []) {
            return;
        }

        $lines = array_map(
            fn (array $attempt): string => "  - {$attempt['layer']} {$attempt['operation']} {$attempt['target']}",
            $attempts,
        );

        Assert::fail(
            "This test tried to reach a relay and the refusal was swallowed on the way up:\n"
            .implode("\n", $lines)
            ."\nBind Tests\\Support\\FakeRelayTransport, or keep the code under test away from the relay.",
        );
    }

    /**
     * Decide for the socket shadow whether an address may be dialled. Records
     * the attempt when it may not.
     */
    public static function permitsSocket(string $address): bool
    {
        if (self::$loopbackAllowed && self::isLoopback($address)) {
            return true;
        }

        self::record('socket', 'connect', $address);

        return false;
    }

    /**
     * Literal loopback addresses only. A host name that happens to resolve to
     * 127.0.0.1 is not loopback here, because deciding that would take a DNS
     * lookup — the very thing this guard exists to prevent.
     */
    private static function isLoopback(string $address): bool
    {
        $host = strtolower(trim((string) parse_url($address, PHP_URL_HOST), '[]'));

        return $host === 'localhost'
            || $host === '::1'
            || preg_match('/^127(\.\d{1,3}){3}$/', $host) === 1;
    }
}
