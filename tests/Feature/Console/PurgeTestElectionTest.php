<?php

use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\EnrolByHand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Services/Election/helpers.php';

/*
 * `election:purge-test` deletes a test election with every row of it, in
 * every election table, and refuses anything else.
 */

/**
 * Rows of `$election` per election table, found the same way as the purge
 * does — by the column that ties a table to an election or a round.
 *
 * @return array<string, int>
 */
function electionRowsPerTable(Election $election, array $roundIds): array
{
    return collect(Schema::getTables())->pluck('name')
        ->filter(fn (string $name): bool => str_starts_with($name, 'election_'))
        ->sort()->values()
        ->mapWithKeys(fn (string $table): array => [$table => match (true) {
            $table === 'election_events' => DB::table($table)->where('id', $election->id)->count(),
            Schema::hasColumn($table, 'election_event_id') => DB::table($table)->where('election_event_id', $election->id)->count(),
            default => DB::table($table)->whereIn('election_round_id', $roundIds)->count(),
        }])
        ->all();
}

/**
 * A test election that has seen everything: candidacies, an opened and
 * sealed round with ballots, a run-off, a hand enrolment, audit lines.
 *
 * @return array{0: Election, 1: list<int>}
 */
function usedTestElection(): array
{
    $board = electionBoard(1);
    $voter = electionVoter();
    $late = EinundzwanzigPleb::factory()->active()->create();
    $election = Election::factory()->test()->create();

    $round = app(ElectionStewardship::class)->open(draftElectionRound(election: $election), $board[0]);
    castElectionBallot($round, $voter, [$round->candidates()->value('id') => 'for']);
    app(EnrolByHand::class)->handle($round, $late, $board[0], 'Probe');
    $round = app(CloseRound::class)->handle($round);

    $runOff = ElectionRound::factory()->withCandidates()->create(['election_event_id' => $election->id, 'parent_round_id' => $round->id, 'position' => 2]);

    return [$election, [$round->id, $runOff->id]];
}

it('deletes every row of a test election in every election table, and only those', function () {
    [$election, $roundIds] = usedTestElection();
    [$other, $otherRoundIds] = usedTestElection();
    $otherBefore = electionRowsPerTable($other, $otherRoundIds);
    $before = electionRowsPerTable($election, $roundIds);

    expect($before['election_ballot_choices'])->toBeGreaterThan(0)
        ->and($before['election_marks'])->toBe(1)
        ->and($before['election_audit_entries'])->toBeGreaterThan(0)
        ->and(count(array_filter($before)))->toBe(count($before));

    $this->artisan('election:purge-test', ['election' => $election->id])->assertSuccessful();

    expect(array_sum(electionRowsPerTable($election, $roundIds)))->toBe(0)
        ->and(electionRowsPerTable($other, $otherRoundIds))->toBe($otherBefore);
});

/*
 * Mutation that must turn this red: drop the `is_test` check in
 * `PurgeTestElection::purge()` — a real election is then wiped.
 */
it('refuses a real election and an unknown id, and deletes nothing', function () {
    $voter = electionVoter();
    $real = Election::factory()->create();
    $round = openElectionRound(election: $real);
    castElectionBallot($round, $voter);
    $before = electionRowsPerTable($real, [$round->id]);

    $this->artisan('election:purge-test', ['election' => $real->id])->assertFailed();
    $this->artisan('election:purge-test', ['election' => 999999])->assertFailed();

    expect(electionRowsPerTable($real, [$round->id]))->toBe($before);
});

it('refuses when an election table belongs to neither an election nor a round', function () {
    [$election, $roundIds] = usedTestElection();
    Schema::create('election_unknown_things', function (Blueprint $table): void {
        $table->id();
    });
    $before = electionRowsPerTable($election, $roundIds);

    $this->artisan('election:purge-test', ['election' => $election->id])->assertFailed();

    expect(electionRowsPerTable($election, $roundIds))->toBe($before);
});

it('covers an election table added later without being told about it', function () {
    [$election, $roundIds] = usedTestElection();
    Schema::create('election_later_notes', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('election_round_id');
    });
    DB::table('election_later_notes')->insert(['election_round_id' => $roundIds[0]]);

    $this->artisan('election:purge-test', ['election' => $election->id])->assertSuccessful();

    expect(DB::table('election_later_notes')->count())->toBe(0);
});
