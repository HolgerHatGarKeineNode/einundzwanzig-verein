<?php

namespace Tests\Engine;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base class of the engine suite: tests that need the production database
 * engine because SQLite in memory cannot show what they are about — a second
 * writer, row locks, a transaction that is dead after a constraint violation.
 *
 * The suite is not part of the default run. scripts/test-engine.sh starts a
 * throwaway container, points both database connections at it and runs
 * tests/Engine.
 *
 * RefreshDatabase begins with migrate:fresh, which drops every table of
 * whatever database is configured. So before any trait is set up, this class
 * refuses to continue unless both connections demonstrably point at the
 * container the script just started. Started any other way — by hand, with a
 * developer's database variables in the environment — the suite fails without
 * having sent a single statement.
 */
abstract class EngineTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUpTraits(): array
    {
        $problems = $this->reasonsNotToTouchTheDatabase();

        if ($problems !== []) {
            $this->fail(
                "The engine suite only runs through scripts/test-engine.sh. Not touching the configured database:\n  - "
                .implode("\n  - ", $problems),
            );
        }

        return parent::setUpTraits();
    }

    /**
     * Names the checks that failed, never the configured values: a host name
     * or a database name of a real system has no business in a test log.
     *
     * @return array<int, string>
     */
    private function reasonsNotToTouchTheDatabase(): array
    {
        $driver = (string) getenv('ENGINE_SUITE_DRIVER');
        $port = (string) getenv('ENGINE_SUITE_PORT');
        $database = (string) getenv('ENGINE_SUITE_DATABASE');

        if ($driver === '' || $port === '' || $database === '') {
            return ['the script did not announce a container (ENGINE_SUITE_DRIVER, _PORT, _DATABASE)'];
        }

        $default = (array) config('database.connections.'.config('database.default'));
        $second = (array) config('database.connections.einundzwanzig');

        $checks = [
            'the announced database is not a throwaway name (engine_…)' => str_starts_with($database, 'engine_'),
            'the default connection uses another driver than the script started' => ($default['driver'] ?? null) === $driver,
            'the default connection does not point at 127.0.0.1' => ($default['host'] ?? null) === '127.0.0.1',
            'the default connection uses another port than the container' => (string) ($default['port'] ?? '') === $port,
            'the default connection names another database than the container' => ($default['database'] ?? null) === $database,
            'the default connection carries a DB_URL, which overrides host and port' => empty($default['url']),
            'the connection "einundzwanzig" does not point at 127.0.0.1' => ($second['host'] ?? null) === '127.0.0.1',
            'the connection "einundzwanzig" uses another port than the container' => (string) ($second['port'] ?? '') === $port,
            'the connection "einundzwanzig" carries a URL, which overrides host and port' => empty($second['url']),
        ];

        return array_keys(array_filter($checks, fn (bool $holds): bool => ! $holds));
    }
}
