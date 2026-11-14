<?php

use App\Models\EinundzwanzigPleb;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
 * The schema on the production engine.
 *
 * The default suite builds it in SQLite, which accepts migrations and values
 * another engine refuses. These cases answer the questions every later engine
 * test takes for granted: are we on that engine at all, do all migrations
 * apply there, and do the unique rules the application leans on exist there.
 *
 * The duplicate inserts sit in DB::transaction() on purpose. RefreshDatabase
 * wraps each test in a transaction, and PostgreSQL declares a transaction
 * dead after a constraint violation — every later statement fails with
 * "current transaction is aborted". The nested transaction is a savepoint;
 * rolling back to it keeps the test's own transaction alive, which the final
 * count in each case shows.
 */

it('talks to the engine the script started and not to SQLite', function () {
    $driver = DB::connection()->getDriverName();

    $databaseAccordingToTheServer = DB::scalar(
        $driver === 'pgsql' ? 'select current_database()' : 'select database()',
    );

    expect($driver)->toBe(getenv('ENGINE_SUITE_DRIVER'))
        ->and($databaseAccordingToTheServer)->toBe(getenv('ENGINE_SUITE_DATABASE'));
});

it('applies every migration of the repository on the engine', function () {
    $files = array_map(
        fn (string $path): string => basename($path, '.php'),
        glob(database_path('migrations/*.php')),
    );

    expect(count($files))->toBeGreaterThan(40)
        ->and(array_diff($files, DB::table('migrations')->pluck('migration')->all()))->toBe([]);
});

it('rejects a second fee for the same member and year', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidYear(2027)->create();

    expect(fn () => DB::transaction(
        fn () => $pleb->paymentEvents()->create(['year' => 2027, 'amount' => 21000]),
    ))->toThrow(UniqueConstraintViolationException::class);

    expect($pleb->paymentEvents()->where('year', 2027)->count())->toBe(1);
});

it('rejects a second member with the same pubkey', function () {
    $pleb = EinundzwanzigPleb::factory()->create();

    expect(fn () => DB::transaction(
        fn () => EinundzwanzigPleb::factory()->create(['pubkey' => $pleb->pubkey]),
    ))->toThrow(UniqueConstraintViolationException::class);

    expect(EinundzwanzigPleb::query()->where('pubkey', $pleb->pubkey)->count())->toBe(1);
});
