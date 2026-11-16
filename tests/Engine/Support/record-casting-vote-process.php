<?php

/*
 * One casting vote in a process of its own, for
 * tests/Engine/ConcurrentCastingVoteTest.php.
 *
 *   php record-casting-vote-process.php <round id> <actor id> <candidate id> <barrier key>
 *
 * The actor is made the board of this process, as electionBoard() does in
 * the test: the board lives in the configuration, not in the database.
 *
 * Boots the application against the database of the engine suite, waits at
 * a PostgreSQL advisory lock the test holds, and records as soon as the test
 * lets go — so that two of these reach `RecordCastingVote` at the same
 * moment. Prints one word: "recorded", or "refused" when RecordCastingVote
 * turned it down.
 *
 * It refuses to touch any database the engine script did not announce, the
 * same check Tests\Engine\EngineTestCase makes before migrating.
 */

use App\Exceptions\ElectionActionRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionRound;
use App\Services\Election\RecordCastingVote;
use App\Support\Board;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';

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

[, $roundId, $actorId, $candidateId, $barrier] = $argv;

$round = ElectionRound::query()->findOrFail((int) $roundId);
$actor = EinundzwanzigPleb::query()->findOrFail((int) $actorId);

config(['einundzwanzig.config.current_board' => [$actor->npub]]);
Board::flush();

DB::select('select pg_advisory_lock_shared(?)', [(int) $barrier]);

try {
    app(RecordCastingVote::class)->handle($round, $actor, [(int) $candidateId], 'Erika Vorsitz');
    echo 'recorded';
} catch (ElectionActionRefusedException) {
    echo 'refused';
}
