<?php

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Exceptions\ElectionRoundStateException;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use App\Services\Election\CreateRunOff;
use App\Services\Election\OpenRound;
use App\Services\Election\RoundReport;
use App\Services\Election\TallyRound;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
 * The count of a round as the application produces it: through the actions,
 * from the stored votes of a sealed round.
 *
 * Mutations that must turn this file red (patches in the plan folder):
 *  - M6b  the tally reads the votes of every round, not of this one
 *  - M6d  `TallyRound` no longer refuses an unsealed round
 *  - M6e  the result carries a list of votes next to the numbers
 *  - B1-E1 `OpenRound` no longer checks earlier rounds for elected candidates
 *  - B1-E2 an undecided tie of the parent round blocks its own run-off
 */

/**
 * Cast `$ballots` ballots, each from a fresh voter put on the roll before the
 * round opens.
 *
 * @param  list<array<array-key, mixed>>  $ballots
 */
function sealedRoundWith(array $ballots, array $attributes = [], int $candidates = 3, ?Election $election = null): ElectionRound
{
    $voters = collect($ballots)->map(fn () => electionVoter());
    $round = app(OpenRound::class)->handle(draftElectionRound($attributes, $candidates, $election));
    $ids = $round->candidates()->orderBy('id')->pluck('id')->all();

    foreach ($ballots as $index => $ballot) {
        // Ballots name candidates by their position in the round: 0, 1, 2 …
        $selection = [];

        foreach ($ballot as $position => $choice) {
            $selection[$ids[$position]] = $choice;
        }

        castElectionBallot($round, $voters[$index], $selection);
    }

    return app(CloseRound::class)->handle($round);
}

it('counts a sealed round with every number written out', function () {
    $round = sealedRoundWith([
        [0 => 'for'], [0 => 'for'], [0 => 'for'],
        [1 => 'for'], [1 => 'for'],
        [], [],
    ]);

    // Ballots in another round of another election must not leak in.
    sealedRoundWith([[0 => 'for'], [1 => 'for'], [1 => 'for']]);

    [$a, $b, $c] = $round->candidates()->orderBy('id')->pluck('id')->all();

    expect(app(TallyRound::class)->handle($round)->toArray())->toBe([
        'rule' => 'single_majority',
        'seats' => 1,
        'outcome' => 'complete',
        'counts' => [
            $a => ['for' => 3, 'yes' => 0, 'no' => 0],
            $b => ['for' => 2, 'yes' => 0, 'no' => 0],
            $c => ['for' => 0, 'yes' => 0, 'no' => 0],
        ],
        'blank' => 2,
        'elected' => [$a],
        'tied' => [],
        'tied_seats' => 0,
        'tie_kind' => null,
    ])->and(electionVoteCount($round))->toBe(7);
});

it('counts nothing while a round is open or merely over by the clock', function () {
    $this->travelTo(Carbon::parse('2027-03-20 10:00:00'));

    $voter = electionVoter();
    $round = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addHour()));
    castElectionBallot($round, $voter, [$round->candidates()->value('id') => 'for']);

    expect(fn () => app(TallyRound::class)->handle($round))->toThrow(ElectionRoundStateException::class);

    $this->travelTo(Carbon::parse('2027-03-20 11:00:00'));

    expect(fn () => app(TallyRound::class)->handle($round->fresh()))->toThrow(ElectionRoundStateException::class);

    app(CloseRound::class)->handle($round);

    expect(app(TallyRound::class)->handle($round->fresh())->elected)->toHaveCount(1);
});

it('counts no annulled round', function () {
    $round = sealedRoundWith([[0 => 'for']]);
    $round->annulled_at = now();
    $round->save();

    expect(fn () => app(TallyRound::class)->handle($round))->toThrow(ElectionRoundStateException::class);
});

it('reports a tie in a sealed round and elects nobody among the level candidates', function () {
    $round = sealedRoundWith(
        [[0 => 'for', 1 => 'for'], [0 => 'for', 2 => 'for'], [1 => 'for', 2 => 'for']],
        ['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2],
    );

    $result = app(TallyRound::class)->handle($round);

    expect($result->outcome)->toBe(ElectionOutcome::Tie)
        ->and($result->elected)->toBe([])
        ->and($result->tied)->toBe($round->candidates()->orderBy('id')->pluck('id')->all())
        ->and($result->tiedSeats)->toBe(2);
});

it('ends a round with fewer elected than seats as understaffed', function () {
    $round = sealedRoundWith(
        [[0 => 'yes', 1 => 'no'], [0 => 'yes', 1 => 'no'], [0 => 'no', 1 => 'yes']],
        ['counting_rule' => ElectionCountingRule::YesNoPerCandidate, 'seats' => 2],
        candidates: 2,
    );

    $result = app(TallyRound::class)->handle($round);

    expect($result->outcome)->toBe(ElectionOutcome::Understaffed)
        ->and($result->elected)->toBe([$round->candidates()->orderBy('id')->value('id')]);
});

/**
 * Let `$round` list the same members as candidates as the given candidates.
 */
function standAgain(ElectionRound $round, ElectionCandidate ...$candidates): void
{
    foreach ($candidates as $candidate) {
        ElectionCandidate::factory()->create([
            'election_round_id' => $round->getKey(),
            'einundzwanzig_pleb_id' => $candidate->einundzwanzig_pleb_id,
        ]);
    }
}

it('does not put a candidate elected in an earlier round on the ballot of a later one', function () {
    $election = Election::factory()->create();
    $presidency = sealedRoundWith([[0 => 'for'], [0 => 'for'], [1 => 'for']], ['position' => 1], candidates: 2, election: $election);
    [$elected, $defeated] = $presidency->candidates()->orderBy('id')->get()->all();

    $board = draftElectionRound(['position' => 2, 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 1, election: $election);
    standAgain($board, $elected, $defeated);

    expect(fn () => app(OpenRound::class)->handle($board))
        ->toThrow(ElectionRoundStateException::class, 'A candidate of this round has been elected in an earlier round of the election.')
        ->and($board->fresh()->opened_at)->toBeNull();

    // The defeated candidate may stand again; only the elected one is out.
    $board->candidates()->where('einundzwanzig_pleb_id', $elected->einundzwanzig_pleb_id)->delete();

    expect(app(OpenRound::class)->handle($board)->opened_at)->not->toBeNull();
});

it('refuses to open a later round while an earlier round with the same candidate has no result yet', function (string $state) {
    $election = Election::factory()->create();
    $earlier = draftElectionRound(['position' => 1], election: $election);

    if ($state !== 'draft') {
        $earlier = app(OpenRound::class)->handle($earlier);
    }

    $later = draftElectionRound(['position' => 2], candidates: 1, election: $election);
    standAgain($later, $earlier->candidates()->first());

    expect(fn () => app(OpenRound::class)->handle($later))
        ->toThrow(ElectionRoundStateException::class, 'A candidate of this round also stands in an earlier round of the election that is not sealed yet.');
})->with(['draft', 'open']);

it('ignores later rounds, annulled rounds, other elections and earlier rounds without a shared candidate', function () {
    $election = Election::factory()->create();
    $later = draftElectionRound(['position' => 5], election: $election);
    $annulled = sealedRoundWith([[0 => 'for']], ['position' => 1], candidates: 1, election: $election);
    $annulled->annulled_at = now();
    $annulled->save();
    $unrelated = draftElectionRound(['position' => 2], election: $election);
    $elsewhere = sealedRoundWith([[0 => 'for']], candidates: 1);

    $round = draftElectionRound(['position' => 3], candidates: 1, election: $election);
    standAgain($round, $later->candidates()->first(), $annulled->candidates()->first(), $elsewhere->candidates()->first());

    expect(fn () => app(OpenRound::class)->handle($round))->not->toThrow(ElectionRoundStateException::class)
        ->and($round->fresh()->opened_at)->not->toBeNull()
        ->and($unrelated->fresh()->opened_at)->toBeNull();
});

it('lets the level candidates of a tie stand in its run-off, and nowhere else', function () {
    $election = Election::factory()->create();
    $parent = sealedRoundWith(
        [[0 => 'for'], [0 => 'for'], [1 => 'for'], [1 => 'for'], [2 => 'for']],
        ['position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes],
        election: $election,
    );
    [$first, $second] = $parent->candidates()->orderBy('id')->get()->all();

    expect(app(TallyRound::class)->handle($parent)->tied)->toBe([$first->id, $second->id]);

    $elsewhere = draftElectionRound(['position' => 2], candidates: 1, election: $election);
    standAgain($elsewhere, $first);

    expect(fn () => app(OpenRound::class)->handle($elsewhere))
        ->toThrow(ElectionRoundStateException::class, 'A candidate of this round is level in an undecided tie of an earlier round of the election.');

    // A draft that shares a candidate would block the run-off as an earlier round without a result.
    $elsewhere->delete();

    $runOff = draftElectionRound(['position' => 3, 'parent_round_id' => $parent->id], candidates: 0, election: $election);
    standAgain($runOff, $first, $second);

    expect(app(OpenRound::class)->handle($runOff)->opened_at)->not->toBeNull();
});

it('treats the round of a run-off as earlier whatever its position', function () {
    $election = Election::factory()->create();
    $parent = sealedRoundWith([[0 => 'for'], [0 => 'for'], [1 => 'for']], ['position' => 9], candidates: 2, election: $election);

    $runOff = draftElectionRound(['position' => 1, 'parent_round_id' => $parent->id], candidates: 1, election: $election);
    standAgain($runOff, $parent->candidates()->orderBy('id')->first());

    expect(fn () => app(OpenRound::class)->handle($runOff))
        ->toThrow(ElectionRoundStateException::class, 'A candidate of this round has been elected in an earlier round of the election.');
});

it('takes a tie as decided once a run-off of it is sealed, and as undecided while it ends level again', function (string $runOffResult) {
    $election = Election::factory()->create();
    $voters = [electionVoter(), electionVoter()];
    $parent = app(OpenRound::class)->handle(draftElectionRound(['position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election));
    [$first, $second] = $parent->candidates()->orderBy('id')->get()->all();
    castElectionBallot($parent, $voters[0], [$first->id => 'for']);
    castElectionBallot($parent, $voters[1], [$second->id => 'for']);
    app(CloseRound::class)->handle($parent);

    $later = draftElectionRound(['position' => 3], candidates: 1, election: $election);
    standAgain($later, $first, $second);

    $runOff = draftElectionRound(['position' => 2, 'parent_round_id' => $parent->id, 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $election);
    standAgain($runOff, $first, $second);
    $runOff = app(OpenRound::class)->handle($runOff);
    [$runOffFirst, $runOffSecond] = $runOff->candidates()->orderBy('id')->get()->all();
    castElectionBallot($runOff, $voters[0], [$runOffFirst->id => 'for']);
    castElectionBallot($runOff, $voters[1], [($runOffResult === 'level again' ? $runOffSecond : $runOffFirst)->id => 'for']);
    app(CloseRound::class)->handle($runOff);

    if ($runOffResult === 'level again') {
        expect(fn () => app(OpenRound::class)->handle($later))
            ->toThrow(ElectionRoundStateException::class, 'A candidate of this round is level in an undecided tie of an earlier round of the election.');

        return;
    }

    // The run-off elected the first; the second, defeated there, may stand.
    expect(fn () => app(OpenRound::class)->handle($later))
        ->toThrow(ElectionRoundStateException::class, 'A candidate of this round has been elected in an earlier round of the election.');

    $later->candidates()->where('einundzwanzig_pleb_id', $first->einundzwanzig_pleb_id)->delete();

    expect(app(OpenRound::class)->handle($later)->opened_at)->not->toBeNull();
})->with(['decided', 'level again']);

it('checks the result of a run-off placed at the end before a later draft with its candidate opens', function (string $runOffResult, string $refusal) {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];

    $chair = draftElectionRound(['position' => 1, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $assessor = draftElectionRound(['position' => 2, 'title' => 'Beisitz'], candidates: 1, election: $election);
    $treasurer = draftElectionRound(['position' => 3, 'title' => 'Kasse'], candidates: 1, election: $election);

    $chair = app(OpenRound::class)->handle($chair);
    [$first, $second] = $chair->candidates()->orderBy('id')->get()->all();
    castElectionBallot($chair, $voters[0], [$first->id => 'for']);
    castElectionBallot($chair, $voters[1], [$second->id => 'for']);
    app(CloseRound::class)->handle($chair);

    // A later round opens before the run-off exists, so the run-off lands at the end.
    app(CloseRound::class)->handle(app(OpenRound::class)->handle($assessor));
    $runOff = app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true);
    expect($runOff->position)->toBeGreaterThan($treasurer->fresh()->position);

    $runOff = app(OpenRound::class)->handle($runOff);
    [$runOffFirst, $runOffSecond] = $runOff->candidates()->orderBy('id')->get()->all();
    castElectionBallot($runOff, $voters[0], [$runOffSecond->id => 'for']);
    castElectionBallot($runOff, $voters[1], [($runOffResult === 'level again' ? $runOffFirst : $runOffSecond)->id => 'for']);
    app(CloseRound::class)->handle($runOff);

    // The run-off's second candidate stands for the treasurer afterwards.
    ElectionCandidate::factory()->create([
        'election_round_id' => $treasurer->id,
        'einundzwanzig_pleb_id' => $second->einundzwanzig_pleb_id,
        'status' => ElectionCandidateStatus::Approved,
    ]);

    expect(fn () => app(OpenRound::class)->handle($treasurer->fresh()))
        ->toThrow(ElectionRoundStateException::class, $refusal);
    expect($treasurer->fresh()->opened_at)->toBeNull();
})->with([
    'elected in the run-off' => ['elected', 'A candidate of this round has been elected in an earlier round of the election.'],
    'level again' => ['level again', 'A candidate of this round is level in an undecided tie of an earlier round of the election.'],
]);

/*
 * Board jury H2: a rejected and a withdrawn candidacy showed as „0 Ja, 0 Nein“
 * in every result, though neither was on the ballot. Mutation that must turn
 * this red: count over every candidacy of the round again.
 */
it('counts exactly the candidates who were on the ballot, and refuses a vote for one who was not', function () {
    $voters = [electionVoter(), electionVoter()];
    $round = draftElectionRound(['counting_rule' => ElectionCountingRule::YesNoPerCandidate], candidates: 1);
    $rejected = ElectionCandidate::factory()->create(['election_round_id' => $round->getKey(), 'status' => ElectionCandidateStatus::Rejected]);
    $withdrawn = ElectionCandidate::factory()->create(['election_round_id' => $round->getKey(), 'status' => ElectionCandidateStatus::Withdrawn]);
    $onBallot = (int) $round->candidates()->where('status', ElectionCandidateStatus::Approved)->value('id');

    $round = app(OpenRound::class)->handle($round);
    castElectionBallot($round, $voters[0], [$onBallot => 'yes']);
    castElectionBallot($round, $voters[1], [$onBallot => 'no']);
    $round = app(CloseRound::class)->handle($round);

    $manifest = json_decode(ElectionManifest::query()->where('election_round_id', $round->getKey())->value('body'), true);
    $report = app(RoundReport::class)->of($round->fresh());

    expect(array_keys(app(TallyRound::class)->handle($round)->counts))->toBe([$onBallot])
        ->and($manifest['candidates'])->toBe([$onBallot])
        ->and(array_column($report['result']['rows'], 'candidate_id'))->toBe([$onBallot])
        ->and(Artisan::call('election:verify', ['round' => $round->getKey()]))->toBe(0);

    // A stored vote for a candidacy that was not on the ballot is not counted
    // as a vote for nobody, nor dropped: the round has no count.
    $vote = DB::table('election_votes')->where('election_round_id', $round->getKey())->orderBy('id')->first();
    DB::table('election_votes')->where('id', $vote->id)->update(['choices' => json_encode([$rejected->getKey() => 'yes'])]);

    expect(fn () => app(TallyRound::class)->handle($round))->toThrow(UnexpectedValueException::class)
        ->and(Artisan::call('election:verify', ['round' => $round->getKey()]))->toBe(1)
        ->and($withdrawn->fresh()->status)->toBe(ElectionCandidateStatus::Withdrawn);
});
