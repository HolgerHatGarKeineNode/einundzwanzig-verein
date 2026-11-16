<?php

/*
 * One cast in a process of its own, for tests/Engine/ConcurrentCastTest.php.
 *
 *   php cast-ballot-process.php <round id> <member id> <signed event json> <barrier key>
 *
 * Boots the application against the database of the engine suite, waits at
 * a PostgreSQL advisory lock the test holds, and casts as soon as the test
 * lets go — so that two of these reach `CastBallot` at the same moment.
 * Prints one word: "cast", or the refusal (e.g. "AlreadyVoted").
 *
 * It refuses to touch any database the engine script did not announce, the
 * same check Tests\Engine\EngineTestCase makes before migrating.
 *
 * Any throwable ends the process with exit code 1 and its class, message and
 * place on stderr. Without the catch, an exception after the Laravel console
 * bootstrap is rendered on stdout and the process exits 0 (measured in jury
 * run 2), so the test's exit-code check could never fail.
 */

use App\Exceptions\ElectionBallotRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionRound;
use App\Services\Election\CastBallot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

try {
    $app = require __DIR__.'/../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $connection = (array) config('database.connections.'.config('database.default'));

    if (
        getenv('ENGINE_SUITE_DATABASE') === false
        || ! str_starts_with((string) getenv('ENGINE_SUITE_DATABASE'), 'engine_')
        || ($connection['driver'] ?? null) !== 'pgsql'
        || ($connection['host'] ?? null) !== '127.0.0.1'
        || (string) ($connection['port'] ?? '') !== (string) getenv('ENGINE_SUITE_PORT')
        || ($connection['database'] ?? null) !== getenv('ENGINE_SUITE_DATABASE')
    ) {
        fwrite(STDERR, "not the engine suite's database\n");
        exit(2);
    }

    [, $roundId, $memberId, $eventJson, $barrier] = $argv;

    $round = ElectionRound::query()->findOrFail((int) $roundId);
    $member = EinundzwanzigPleb::query()->findOrFail((int) $memberId);

    DB::select('select pg_advisory_lock_shared(?)', [(int) $barrier]);

    try {
        app(CastBallot::class)->handle($round, $member, json_decode($eventJson, true, flags: JSON_THROW_ON_ERROR));
        echo 'cast';
    } catch (ElectionBallotRefusedException $refusal) {
        echo $refusal->reason->name;
    }
} catch (Throwable $throwable) {
    fwrite(STDERR, sprintf("%s: %s in %s:%d\n", $throwable::class, $throwable->getMessage(), $throwable->getFile(), $throwable->getLine()));
    exit(1);
}
