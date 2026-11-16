<?php

namespace App\Console\Commands;

use App\Services\Election\PurgeTestElection as PurgeTestElectionService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Deletes a TEST election and every row of it in every `election_*` table;
 * refuses a real election. The work is done by
 * `App\Services\Election\PurgeTestElection`, which the board's page uses too.
 */
class PurgeTestElection extends Command
{
    protected $signature = 'election:purge-test {election : The id of the test election}';

    protected $description = 'Delete a test election and every row of it in every election table';

    public function handle(PurgeTestElectionService $purge): int
    {
        try {
            $counts = $purge->handle((int) $this->argument('election'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        foreach ($counts as $table => $count) {
            $this->line(sprintf('%s: %d', $table, $count));
        }

        $this->info('Test election purged.');

        return self::SUCCESS;
    }
}
