<?php

declare(strict_types=1);

/**
 * Router for `php -S` in the election/DM e2e run (scripts/e2e-election.sh).
 *
 * It is public/index.php plus five overrides, applied after the providers
 * booted and before the request is routed, so the application code itself
 * stays exactly what production runs:
 *
 *   - RelayTransport is an in-memory fake (Tests\Support\FakeRelayTransport).
 *     AppServiceProvider binds the real WebSocket transport outside `testing`;
 *     a DM sending in the browser run would otherwise dial the members' inbox
 *     relays. Every publish is accepted, nothing leaves the process.
 *   - The board is the throwaway board the fixture wrote to
 *     $E2E_STATE_DIR/board.json (election-fixture.php). The configured board
 *     is a list of real npubs whose keys a test can never hold.
 *   - Sessions and the log live in $E2E_STATE_DIR, not in storage/, so a run
 *     neither reads nor leaves state in the developer's checkout.
 *   - Time travel: when $E2E_STATE_DIR/clock-offset exists, every request runs
 *     that many seconds in the future (Carbon::setTestNow). A spec moves the
 *     page clock by the same amount, so an idle tab can be aged past a TTL
 *     without waiting for it (tests/e2e/login-stale-tab.spec.js). No file,
 *     no offset.
 *
 * Static files under public/ are served by the built-in server itself, as
 * vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php does.
 */

use App\Services\Nostr\RelayTransport;
use App\Support\Board;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Support\FakeRelayTransport;

$root = dirname(__DIR__, 3);
$public = $root.'/public';
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && is_file($public.$uri)) {
    return false;
}

$state = getenv('E2E_STATE_DIR');

if (! is_string($state) || $state === '' || ! is_dir($state)) {
    http_response_code(500);
    echo 'E2E_STATE_DIR is not set: start the server through scripts/e2e-election.sh';

    return true;
}

define('LARAVEL_START', microtime(true));

$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

require $root.'/vendor/autoload.php';

$clockOffset = $state.'/clock-offset';

if (is_file($clockOffset)) {
    Carbon::setTestNow(Carbon::now()->addSeconds((int) trim((string) file_get_contents($clockOffset))));
}

$app = require $root.'/bootstrap/app.php';

$app->booted(function ($app) use ($state): void {
    $app->instance(RelayTransport::class, new FakeRelayTransport);

    $board = is_file($state.'/board.json') ? json_decode((string) file_get_contents($state.'/board.json'), true) : [];

    config([
        'einundzwanzig.config.current_board' => is_array($board) ? array_values($board) : [],
        'session.files' => $state.'/sessions',
        'logging.channels.single.path' => $state.'/laravel.log',
    ]);

    Board::flush();
});

$app->handleRequest(Request::capture());
