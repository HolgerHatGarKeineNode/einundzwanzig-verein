<?php

namespace App\Services\Election;

use App\Exceptions\ElectionActionRefusedException;
use App\Models\Election;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Deletes a TEST election and every row that belongs to it, in every
 * `election_*` table — rounds, candidacies, roll, votes, manifests, audit
 * lines, the election itself. Behind `election:purge-test` and the board's
 * "Probewahl löschen" button.
 *
 * The tables are discovered, not listed: whatever `election_*` table a later
 * change adds is covered without touching this class, and this class names
 * no table that holds ballots. A table that belongs to neither an election
 * (`election_event_id`) nor a round (`election_round_id`) is unknown here,
 * and the purge refuses rather than leave rows behind.
 *
 * Bypasses the models on purpose: they refuse to delete an opened round,
 * its candidates or an audit line, and a test election is exactly where
 * those guards are not wanted. That is why `is_test` is checked first, on a
 * locked row, and why a real election is refused outright.
 */
class PurgeTestElection
{
    /**
     * @return array<string, int> rows deleted per table
     *
     * @throws ElectionActionRefusedException when the election is not a test election
     * @throws RuntimeException when the election does not exist or rows would be left behind
     */
    public function handle(int $electionId): array
    {
        return DB::transaction(fn (): array => $this->purge($electionId));
    }

    /**
     * @return array<string, int> rows deleted per table
     */
    protected function purge(int $electionId): array
    {
        $election = Election::query()->lockForUpdate()->find($electionId);

        if ($election === null) {
            throw new RuntimeException("Election {$electionId} does not exist.");
        }

        if (! $election->is_test) {
            throw new ElectionActionRefusedException("Election {$electionId} is not a test election. Refused.");
        }

        ['byElection' => $byElection, 'byRound' => $byRound] = $this->electionTables();
        $roundIds = DB::table('election_rounds')->where('election_event_id', $electionId)->pluck('id')->all();
        $counts = [];

        // Round-linked rows first; candidacies after the rows that point at them.
        usort($byRound, fn (string $a, string $b): int => ($a === 'election_candidates') <=> ($b === 'election_candidates'));

        foreach ($byRound as $table) {
            $counts[$table] = DB::table($table)->whereIn('election_round_id', $roundIds)->delete();
        }

        DB::table('election_rounds')->whereIn('id', $roundIds)->update(['parent_round_id' => null]);

        foreach ($byElection as $table) {
            $counts[$table] = DB::table($table)->where('election_event_id', $electionId)->delete();
        }

        $counts['election_events'] = DB::table('election_events')->where('id', $electionId)->delete();

        foreach ([...$byRound, ...$byElection] as $table) {
            $left = Schema::hasColumn($table, 'election_event_id')
                ? DB::table($table)->where('election_event_id', $electionId)->count()
                : DB::table($table)->whereIn('election_round_id', $roundIds)->count();

            if ($left !== 0) {
                throw new RuntimeException("{$left} rows of election {$electionId} are left in {$table}. Nothing was deleted.");
            }
        }

        return $counts;
    }

    /**
     * Every `election_*` table except the elections themselves, split by the
     * column that ties its rows to an election. A table with both columns
     * goes by the election, so rows of a round deleted earlier go too.
     *
     * @return array{byElection: list<string>, byRound: list<string>}
     */
    protected function electionTables(): array
    {
        $byElection = [];
        $byRound = [];

        $tables = collect(Schema::getTables())->pluck('name')->unique()
            ->filter(fn (string $name): bool => str_starts_with($name, 'election_') && $name !== 'election_events')
            ->sort()
            ->values();

        foreach ($tables as $table) {
            if (Schema::hasColumn($table, 'election_event_id')) {
                $byElection[] = $table;
            } elseif (Schema::hasColumn($table, 'election_round_id')) {
                $byRound[] = $table;
            } else {
                throw new RuntimeException("The table {$table} belongs to neither an election nor a round. Refused.");
            }
        }

        // The rounds go after everything that names them.
        usort($byElection, fn (string $a, string $b): int => ($a === 'election_rounds') <=> ($b === 'election_rounds'));

        return ['byElection' => $byElection, 'byRound' => $byRound];
    }
}
