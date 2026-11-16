<?php

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Election\Candidacies;
use App\Services\Election\CloseRound;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\OpenRound;
use App\Services\Election\TallyRound;
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/helpers.php';

/*
 * "Whoever is elected, or still undecided in a tie, does not stand in a later
 * round" over a whole run-off chain: a first-level round and every run-off
 * below it, at any depth, ordered by the position of the first-level round.
 *
 * Every row builds the chain through the actions (`CreateRunOff` decides where
 * a run-off lands), then lets a later round — the treasurer — try to open with
 * one of the candidates of the chain.
 *
 * Mutations that must turn this file red:
 *  - the decisive result of a chain is read from its first sealed round
 *    instead of its deepest one
 *  - the order of a run-off is its direct parent's position instead of the
 *    position of the first-level round of its chain
 */

/**
 * The refusal `$open` ends with, by the part of its message the admin page
 * maps to a German text, or `opens`.
 */
function runOffChainOutcome(Closure $open): string
{
    try {
        $open();
    } catch (ElectionRoundStateException $exception) {
        return match (true) {
            str_contains($exception->getMessage(), 'not sealed yet') => 'not sealed',
            str_contains($exception->getMessage(), 'also stands in the opened round') => 'open elsewhere',
            str_contains($exception->getMessage(), 'has been elected') => 'elected',
            str_contains($exception->getMessage(), 'undecided tie') => 'tie',
            str_contains($exception->getMessage(), 'second ballot is owed') => 'owed',
            default => throw $exception,
        };
    }

    return 'opens';
}

/**
 * `$voter` names the candidate of `$round` who is member `$plebId`.
 */
function runOffChainVote(ElectionRound $round, EinundzwanzigPleb $voter, int $plebId): void
{
    $candidateId = $round->candidates()->where('einundzwanzig_pleb_id', $plebId)->value('id');

    castElectionBallot($round, $voter, [$candidateId => 'for']);
}

/**
 * Open `$round`, have the voters name the given members one each, and seal it.
 *
 * @param  list<EinundzwanzigPleb>  $voters
 * @param  list<int>  $plebIds
 */
function runOffChainVoteAndSeal(ElectionRound $round, array $voters, array $plebIds): ElectionRound
{
    $round = app(OpenRound::class)->handle($round->fresh());

    foreach ($plebIds as $index => $plebId) {
        runOffChainVote($round, $voters[$index], $plebId);
    }

    return app(CloseRound::class)->handle($round);
}

/**
 * Member `$plebId` stands, approved, in `$round`.
 */
function runOffChainStand(ElectionRound $round, int $plebId): void
{
    ElectionCandidate::factory()->create([
        'election_round_id' => $round->getKey(),
        'einundzwanzig_pleb_id' => $plebId,
        'status' => ElectionCandidateStatus::Approved,
    ]);
}

/**
 * The member ids of the candidates of `$round`, in the order they were added.
 *
 * @return list<int>
 */
function runOffChainMembers(ElectionRound $round): array
{
    return $round->candidates()->orderBy('id')->pluck('einundzwanzig_pleb_id')->map(fn (mixed $id): int => (int) $id)->all();
}

it('decides whether a later round with a candidate of a run-off chain opens', function (string $placement, string $outcome, string $standing, string $expected) {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];

    $chair = draftElectionRound(['position' => 1, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $assessor = draftElectionRound(['position' => 2, 'title' => 'Beisitz'], candidates: 1, election: $election);
    $treasurer = draftElectionRound(['position' => 3, 'title' => 'Kasse'], candidates: 1, election: $election);
    [$a, $b] = runOffChainMembers($chair);

    // 1:1 — a tie between A and B.
    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $b]);

    if ($placement === 'at the end') {
        // A later round is opened before the run-off exists.
        runOffChainVoteAndSeal($assessor, [], []);
    }

    $runOff = app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true);

    expect($runOff->position > $treasurer->fresh()->position)->toBe($placement === 'at the end');

    switch ($outcome) {
        case 'draft':
            break;

        case 'open':
            app(OpenRound::class)->handle($runOff);
            break;

        case 'elects A':
            runOffChainVoteAndSeal($runOff, $voters, [$a, $a]);
            break;

        case 'level again':
            runOffChainVoteAndSeal($runOff, $voters, [$a, $b]);
            break;

        case 'annulled':
            app(ElectionStewardship::class)->annul(app(OpenRound::class)->handle($runOff), $boardMember, 'Wrong roll.');
            break;

        case 'annulled, new run-off elects A':
            app(ElectionStewardship::class)->annul(app(OpenRound::class)->handle($runOff), $boardMember, 'Wrong roll.');
            runOffChainVoteAndSeal(app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true), $voters, [$a, $a]);
            break;

        default:
            // A second run-off of the first one, which ends level again.
            $runOff = runOffChainVoteAndSeal($runOff, $voters, [$a, $b]);

            if ($placement === 'second at the end') {
                runOffChainVoteAndSeal($assessor, [], []);
            }

            $secondRunOff = app(CreateRunOff::class)->handle($runOff, $boardMember, resolvedByAssembly: true);

            expect($secondRunOff->position > $treasurer->fresh()->position)->toBe($placement !== 'directly after');

            match ($outcome) {
                'second draft' => null,
                'second elects A' => runOffChainVoteAndSeal($secondRunOff, $voters, [$a, $a]),
                'second level again' => runOffChainVoteAndSeal($secondRunOff, $voters, [$a, $b]),
            };
    }

    runOffChainStand($treasurer, match ($standing) {
        'A' => $a,
        'B' => $b,
        'someone else' => (int) EinundzwanzigPleb::factory()->active()->create()->id,
    });

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe($expected)
        ->and($treasurer->fresh()->opened_at !== null)->toBe($expected === 'opens');
})->with(function (): array {
    $rows = [];

    foreach (['directly after', 'at the end'] as $placement) {
        foreach ([
            ['draft', 'A', 'tie'],
            ['draft', 'someone else', 'opens'],
            ['open', 'A', 'tie'],
            ['open', 'B', 'tie'],
            ['elects A', 'A', 'elected'],
            ['elects A', 'B', 'opens'],
            ['elects A', 'someone else', 'opens'],
            ['level again', 'A', 'tie'],
            ['level again', 'B', 'tie'],
            ['level again', 'someone else', 'opens'],
            ['annulled', 'A', 'tie'],
            ['annulled', 'someone else', 'opens'],
            ['annulled, new run-off elects A', 'A', 'elected'],
            ['annulled, new run-off elects A', 'B', 'opens'],
            ['second draft', 'A', 'tie'],
            ['second elects A', 'A', 'elected'],
            ['second elects A', 'B', 'opens'],
            ['second level again', 'A', 'tie'],
            ['second level again', 'B', 'tie'],
            ['second level again', 'someone else', 'opens'],
        ] as [$outcome, $standing, $expected]) {
            $rows["run-off {$placement}, {$outcome}, treasurer with {$standing}"] = [$placement, $outcome, $standing, $expected];
        }
    }

    foreach ([
        ['second draft', 'A', 'tie'],
        ['second draft', 'someone else', 'opens'],
        ['second elects A', 'A', 'elected'],
        ['second elects A', 'B', 'opens'],
        ['second level again', 'A', 'tie'],
        ['second level again', 'B', 'tie'],
        ['second level again', 'someone else', 'opens'],
    ] as [$outcome, $standing, $expected]) {
        $rows["first run-off directly after, second at the end, {$outcome}, treasurer with {$standing}"] = ['second at the end', $outcome, $standing, $expected];
    }

    return $rows;
});

it('lets the loser of a decided chair stand for the treasurer, and not the winner', function (string $placement, string $standing, string $expected) {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter(), electionVoter(), electionVoter()];

    $chair = draftElectionRound(['position' => 1, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $assessor = draftElectionRound(['position' => 2, 'title' => 'Beisitz'], candidates: 1, election: $election);
    $treasurer = draftElectionRound(['position' => 3, 'title' => 'Kasse'], candidates: 1, election: $election);
    [$a, $b] = runOffChainMembers($chair);

    // 2:2, then 3:0 for A in the run-off.
    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $a, $b, $b]);

    if ($placement === 'at the end') {
        runOffChainVoteAndSeal($assessor, [], []);
    }

    runOffChainVoteAndSeal(app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true), $voters, [$a, $a, $a]);

    runOffChainStand($treasurer, $standing === 'the loser' ? $b : $a);

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe($expected);
})->with([
    'run-off directly after, the loser' => ['directly after', 'the loser', 'opens'],
    'run-off directly after, the winner' => ['directly after', 'the winner', 'elected'],
    'run-off at the end, the loser' => ['at the end', 'the loser', 'opens'],
    'run-off at the end, the winner' => ['at the end', 'the winner', 'elected'],
]);

it('takes a run-off of a single-majority round without a majority as deciding it', function (string $outcome, string $standing, string $expected) {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = collect(range(1, 6))->map(fn (): EinundzwanzigPleb => electionVoter())->all();

    $chair = draftElectionRound(['position' => 1, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::SingleMajority], candidates: 3, election: $election);
    $treasurer = draftElectionRound(['position' => 2, 'title' => 'Kasse'], candidates: 1, election: $election);
    [$a, $b, $c] = runOffChainMembers($chair);

    // 3 of 6 is not more than half: nobody is elected, A and B are the two best placed.
    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $a, $a, $b, $b, $c]);

    expect(app(TallyRound::class)->handle($chair)->outcome)->toBe(ElectionOutcome::Understaffed);

    if ($outcome !== 'no run-off yet') {
        $runOff = app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true);

        expect(runOffChainMembers($runOff))->toBe([$a, $b]);
    }

    if ($outcome === 'elects B') {
        runOffChainVoteAndSeal($runOff, $voters, [$b, $b]);
    }

    if ($outcome === 'annulled') {
        app(ElectionStewardship::class)->annul(app(OpenRound::class)->handle($runOff), $boardMember, 'Wrong roll.');
    }

    runOffChainStand($treasurer, match ($standing) {
        'A' => $a,
        'B' => $b,
        'C' => $c,
    });

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe($expected);
})->with([
    'no run-off yet, treasurer with A' => ['no run-off yet', 'A', 'owed'],
    'no run-off yet, treasurer with B' => ['no run-off yet', 'B', 'owed'],
    'no run-off yet, treasurer with C, who would not be in it' => ['no run-off yet', 'C', 'opens'],
    'run-off annulled, treasurer with A' => ['annulled', 'A', 'owed'],
    'run-off annulled, treasurer with C' => ['annulled', 'C', 'opens'],
    'run-off draft, treasurer with A' => ['draft', 'A', 'not sealed'],
    'run-off draft, treasurer with C, who is not in it' => ['draft', 'C', 'opens'],
    'run-off elects B, treasurer with B' => ['elects B', 'B', 'elected'],
    'run-off elects B, treasurer with A' => ['elects B', 'A', 'opens'],
]);

it('opens a run-off at the end after an unrelated later round was sealed, and a second run-off of a level first one', function () {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];

    $chair = draftElectionRound(['position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $assessor = draftElectionRound(['position' => 2], candidates: 1, election: $election);
    $treasurer = draftElectionRound(['position' => 3], candidates: 1, election: $election);
    [$a, $b] = runOffChainMembers($chair);

    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $b]);
    runOffChainVoteAndSeal($assessor, [], []);
    runOffChainVoteAndSeal($treasurer, [], []);

    $runOff = app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true);
    expect($runOff->position)->toBe(4);

    $runOff = runOffChainVoteAndSeal($runOff, $voters, [$a, $b]);
    $secondRunOff = app(CreateRunOff::class)->handle($runOff, $boardMember, resolvedByAssembly: true);

    expect(app(OpenRound::class)->handle($secondRunOff)->opened_at)->not->toBeNull();
});

it('does not let a run-off of a later round block an earlier round, but never elects its winner twice', function () {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];

    $early = draftElectionRound(['position' => 3, 'title' => 'Früh'], candidates: 1, election: $election);
    $late = draftElectionRound(['position' => 4, 'title' => 'Spät', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $other = draftElectionRound(['position' => 5], candidates: 1, election: $election);
    [$a, $b] = runOffChainMembers($late);

    $late = runOffChainVoteAndSeal($late, $voters, [$a, $b]);
    runOffChainVoteAndSeal($other, [], []);
    $runOff = runOffChainVoteAndSeal(app(CreateRunOff::class)->handle($late, $boardMember, resolvedByAssembly: true), $voters, [$a, $a]);
    expect($runOff->position)->toBe(6);

    runOffChainStand($early, $b);
    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($early->fresh())))->toBe('opens');

    $earlyTwo = draftElectionRound(['position' => 2, 'title' => 'Noch früher'], candidates: 1, election: $election);
    runOffChainStand($earlyTwo, $a);
    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($earlyTwo->fresh())))->toBe('elected');
});

it('opens a run-off at the end before an intervening draft in which a tied candidate stands', function () {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];

    $chair = draftElectionRound(['position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $assessor = draftElectionRound(['position' => 2], candidates: 1, election: $election);
    $treasurer = draftElectionRound(['position' => 3], candidates: 1, election: $election);
    [$a, $b] = runOffChainMembers($chair);
    runOffChainStand($treasurer, $a);

    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $b]);
    runOffChainVoteAndSeal($assessor, [], []);
    $runOff = app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true);

    // The treasurer comes after the chair, so it waits for the run-off — not the other way round.
    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('tie')
        ->and(runOffChainOutcome(fn () => app(OpenRound::class)->handle($runOff->fresh())))->toBe('opens');
});

/*
 * A round under an annulled round has no result. Mutation that must turn
 * this red: count every non-annulled round, whatever is annulled above it.
 */
it('lets the repeat of a chair open that was annulled while its run-off was still a draft', function () {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];

    $chair = draftElectionRound(['position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $treasurer = draftElectionRound(['position' => 2], candidates: 1, election: $election);
    [$a, $b] = runOffChainMembers($chair);
    runOffChainStand($treasurer, $a);

    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $b]);
    app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true);

    $stewardship = app(ElectionStewardship::class);
    $chair = $stewardship->annul($chair, $boardMember, 'Falsche Liste');
    $repeat = $stewardship->repeat($chair, $boardMember);

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($repeat->fresh())))->toBe('opens');
});

it('ignores a sealed run-off below an annulled run-off', function () {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];

    $chair = draftElectionRound(['position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $treasurer = draftElectionRound(['position' => 2], candidates: 1, election: $election);
    [$a, $b] = runOffChainMembers($chair);

    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $b]);
    $runOff = runOffChainVoteAndSeal(app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true), $voters, [$a, $b]);
    runOffChainVoteAndSeal(app(CreateRunOff::class)->handle($runOff, $boardMember, resolvedByAssembly: true), $voters, [$a, $a]);
    app(ElectionStewardship::class)->annul($runOff, $boardMember, 'Falsche Liste');

    // The tie of the chair is open again; A's win below the annulled run-off counts for nothing.
    $newRunOff = app(CreateRunOff::class)->handle($chair->fresh(), $boardMember, resolvedByAssembly: true);
    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($newRunOff->fresh())))->toBe('opens');

    runOffChainVote($newRunOff->fresh(), $voters[0], $a);
    runOffChainVote($newRunOff->fresh(), $voters[1], $b);
    app(CloseRound::class)->handle($newRunOff->fresh());
    runOffChainStand($treasurer, $a);
    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('tie');
});

it('frees a later round once the blocking candidacy is withdrawn, in either round', function (string $where) {
    $election = Election::factory()->create();

    $chair = draftElectionRound(['position' => 1], candidates: 1, election: $election);
    $treasurer = draftElectionRound(['position' => 2], candidates: 1, election: $election);
    [$a] = runOffChainMembers($chair);
    runOffChainStand($treasurer, $a);

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('not sealed');

    $blocking = $where === 'earlier' ? $chair : $treasurer;
    $blocking->candidates()->where('einundzwanzig_pleb_id', $a)->update(['status' => ElectionCandidateStatus::Withdrawn]);

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('opens');
})->with(['earlier', 'in the round to open']);

/*
 * Board jury H1: Präsidium 6:5:3 sealed without a majority, then Vorstand
 * opened with Anna standing in both — Anna was elected to Vorstand and the
 * Präsidium run-off refused her. Mutation that must turn this red: drop the
 * owed run-off check from `assertContestDecidedFor()`.
 */
it('refuses a later round with a candidate of an owed run-off, and opens it once the run-off is decided', function () {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = collect(range(1, 14))->map(fn (): EinundzwanzigPleb => electionVoter())->all();

    $presidium = draftElectionRound(['position' => 1, 'title' => 'Präsidium', 'counting_rule' => ElectionCountingRule::SingleMajority], candidates: 3, election: $election);
    $board = draftElectionRound(['position' => 2, 'title' => 'Vorstand', 'counting_rule' => ElectionCountingRule::YesNoPerCandidate], candidates: 1, election: $election);
    [$anna, $bernd, $eva] = runOffChainMembers($presidium);
    runOffChainStand($board, $anna);

    // 6:5:3 — 6 of 14 is not more than half; Anna and Bernd go to the run-off.
    $presidium = runOffChainVoteAndSeal($presidium, $voters, [...array_fill(0, 6, $anna), ...array_fill(0, 5, $bernd), ...array_fill(0, 3, $eva)]);

    try {
        app(OpenRound::class)->handle($board->fresh());
        $this->fail('Vorstand opened while the Präsidium run-off is owed.');
    } catch (ElectionCandidateBlockedException $refusal) {
        expect($refusal->getMessage())->toContain('second ballot is owed')
            ->toContain('create that second ballot first')
            ->and($refusal->memberId)->toBe($anna);
    }

    expect($board->fresh()->opened_at)->toBeNull()
        ->and(app(OpenRound::class)->refusal($board->fresh()))->toBeInstanceOf(ElectionCandidateBlockedException::class);

    // The run-off can still be created and decides the seat; Bernd wins, Anna is free.
    $runOff = app(CreateRunOff::class)->handle($presidium, $boardMember);
    expect(runOffChainMembers($runOff))->toBe([$anna, $bernd]);
    runOffChainVoteAndSeal($runOff, $voters, [$bernd, $bernd, $anna]);

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($board->fresh())))->toBe('opens');
});

it('refuses a run-off of a chair with a candidate elected meanwhile in a round placed before it', function () {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = collect(range(1, 6))->map(fn (): EinundzwanzigPleb => electionVoter())->all();

    $treasurer = draftElectionRound(['position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 1, election: $election);
    $chair = draftElectionRound(['position' => 2, 'counting_rule' => ElectionCountingRule::SingleMajority], candidates: 3, election: $election);
    [$a, $b] = runOffChainMembers($chair);

    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $a, $a, $b, $b, runOffChainMembers($chair)[2]]);

    // The treasurer comes first, so the owed run-off of the later chair does not hold it back.
    // Wahlreglement § 4 Nr. 1 „Gewählt wird in der festgelegten Reihenfolge.“ and
    // § 3 Nr. 7 „Wer in einem Wahlgang gewählt ist, steht in den folgenden
    // Wahlgängen nicht mehr zur Wahl.“: the earlier office has the first claim.
    runOffChainStand($treasurer, $a);
    runOffChainVoteAndSeal($treasurer, $voters, [$a]);
    $runOff = app(CreateRunOff::class)->handle($chair, $boardMember);

    // Nobody is elected twice, whatever the order of the contests.
    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($runOff->fresh())))->toBe('elected');

    // No deadlock: once A's candidacy in the run-off is withdrawn, the run-off opens.
    app(Candidacies::class)->withdraw($runOff->candidates()->where('einundzwanzig_pleb_id', $a)->firstOrFail(), $boardMember, 'Als Kassier gewählt');

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($runOff->fresh())))->toBe('opens');
});

/*
 * Annulling a round takes every run-off below it along, whatever its state,
 * so no void round can be opened, decide a seat or get a run-off of its own.
 * Mutation that must turn this red: drop `annulRunOffsOf()` from `annul()`.
 */
it('annuls every run-off below an annulled round, so none of them can be opened or get a run-off', function (string $state) {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];

    $chair = draftElectionRound(['position' => 1, 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
    $treasurer = draftElectionRound(['position' => 2], candidates: 1, election: $election);
    [$a, $b] = runOffChainMembers($chair);
    runOffChainStand($treasurer, $a);

    $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $b]);
    $runOff = app(CreateRunOff::class)->handle($chair, $boardMember, resolvedByAssembly: true);
    $second = null;

    if ($state === 'open') {
        $runOff = app(OpenRound::class)->handle($runOff->fresh());
    }

    if ($state === 'sealed level, second run-off sealed and elects A') {
        $runOff = runOffChainVoteAndSeal($runOff, $voters, [$a, $b]);
        $second = runOffChainVoteAndSeal(app(CreateRunOff::class)->handle($runOff, $boardMember, resolvedByAssembly: true), $voters, [$a, $a]);
    }

    app(ElectionStewardship::class)->annul($chair, $boardMember, 'Falsche Liste');

    expect($runOff->fresh()->annulled_at)->not->toBeNull()
        ->and(fn () => app(CreateRunOff::class)->handle($runOff->fresh(), $boardMember, resolvedByAssembly: true))->toThrow(ElectionActionRefusedException::class)
        ->and(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('opens');

    if ($second !== null) {
        expect($second->fresh()->annulled_at)->not->toBeNull();
    }
})->with(['draft', 'open', 'sealed level, second run-off sealed and elects A']);

/*
 * Gate finding j2g: a round placed earlier was opened while a candidate of it
 * stood in a later round that was already open — the later chair's run-off
 * (P3) or the later chair itself (P4) — and both rounds elected the same
 * member. Wahlreglement § 3 Nr. 7 „Wer in einem Wahlgang gewählt ist, steht in
 * den folgenden Wahlgängen nicht mehr zur Wahl.“ Mutation that must turn this
 * red: drop the open-elsewhere check from
 * `assertNobodyStandsAgainAfterBeingElected()`.
 */

/**
 * Treasurer at position 1, chair at position 2, and A standing in an opened,
 * unsealed round of the chair: its run-off after 3:2:1 without a majority
 * (`run-off`), or the chair itself (`chair`). Returns the round A stands in
 * there, the treasurer, A, B, the voters and a board member.
 *
 * @return array{0: ElectionRound, 1: ElectionRound, 2: int, 3: int, 4: list<EinundzwanzigPleb>, 5: EinundzwanzigPleb}
 */
function runOffChainOpenLaterRound(string $where): array
{
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $voters = collect(range(1, 6))->map(fn (): EinundzwanzigPleb => electionVoter())->all();

    $treasurer = draftElectionRound(['position' => 1, 'title' => 'Kasse', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 1, election: $election);

    if ($where === 'run-off') {
        $chair = draftElectionRound(['position' => 2, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::SingleMajority], candidates: 3, election: $election);
        [$a, $b, $c] = runOffChainMembers($chair);

        // 3 of 6 is not more than half: nobody is elected, A and B go to the run-off.
        $chair = runOffChainVoteAndSeal($chair, $voters, [$a, $a, $a, $b, $b, $c]);
        $later = app(OpenRound::class)->handle(app(CreateRunOff::class)->handle($chair, $boardMember));
    } else {
        $chair = draftElectionRound(['position' => 2, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 2, election: $election);
        [$a, $b] = runOffChainMembers($chair);
        $later = app(OpenRound::class)->handle($chair);
    }

    expect(runOffChainMembers($later))->toContain($a)->toContain($b);

    return [$later, $treasurer, $a, $b, $voters, $boardMember];
}

it('refuses an earlier round while a candidate of it stands in an open later round, so nobody is elected twice', function (string $where) {
    [$later, $treasurer, $a] = runOffChainOpenLaterRound($where);
    runOffChainStand($treasurer, $a);

    try {
        app(OpenRound::class)->handle($treasurer->fresh());
        $this->fail('The treasurer opened while A stands in the open round "'.$later->title.'".');
    } catch (ElectionCandidateBlockedException $refusal) {
        expect($refusal->getMessage())->toContain('also stands in the opened round')
            ->toContain('"'.$later->title.'"')
            ->and($refusal->memberId)->toBe($a)
            ->and($refusal->roundTitle)->toBe($later->title);
    }

    expect($treasurer->fresh()->opened_at)->toBeNull()
        ->and(app(OpenRound::class)->refusal($treasurer->fresh()))->toBeInstanceOf(ElectionCandidateBlockedException::class);
})->with([
    'P3: the later chair\'s run-off is open' => ['run-off'],
    'P4: the later chair is open' => ['chair'],
]);

it('leaves no deadlock: the refused earlier round opens once the later round is closed or the candidacy withdrawn', function (string $where, string $way) {
    [$later, $treasurer, $a, $b, $voters, $boardMember] = runOffChainOpenLaterRound($where);
    runOffChainStand($treasurer, $a);

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('open elsewhere');

    if ($way === 'withdrawn') {
        app(Candidacies::class)->withdraw($treasurer->candidates()->where('einundzwanzig_pleb_id', $a)->firstOrFail(), $boardMember, 'Kandidiert im Vorsitz');
    } else {
        $winner = $way === 'later round closed, elects A' ? $a : $b;
        runOffChainVote($later, $voters[0], $winner);
        app(CloseRound::class)->handle($later->fresh());

        expect(app(TallyRound::class)->effective($later->fresh())->elected)
            ->toBe([(int) $later->candidates()->where('einundzwanzig_pleb_id', $winner)->value('id')]);
    }

    if ($way === 'later round closed, elects A') {
        // A is elected there now: the treasurer refuses A for good, and opens once A's candidacy is withdrawn.
        expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('elected');
        app(Candidacies::class)->withdraw($treasurer->candidates()->where('einundzwanzig_pleb_id', $a)->firstOrFail(), $boardMember, 'Im Vorsitz gewählt');
    }

    expect(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('opens');
})->with(function (): array {
    $rows = [];

    foreach (['P3' => 'run-off', 'P4' => 'chair'] as $label => $where) {
        foreach (['later round closed, elects B', 'later round closed, elects A', 'withdrawn'] as $way) {
            $rows["{$label}, {$way}"] = [$where, $way];
        }
    }

    return $rows;
});

/*
 * Adding or approving a candidacy never goes around the open-time check: a
 * draft round takes the candidacy and refuses to open, an opened round takes
 * none at all.
 */
it('takes a candidacy into an earlier draft while a later round is open, and still refuses to open it', function () {
    $election = Election::factory()->create();
    [$boardMember] = electionBoard(1);
    $a = electionVoter();

    $treasurer = draftElectionRound(['position' => 1, 'title' => 'Kasse'], candidates: 1, election: $election);
    $chair = draftElectionRound(['position' => 2, 'title' => 'Vorsitz'], candidates: 1, election: $election);
    runOffChainStand($chair, (int) $a->id);
    $chair = app(OpenRound::class)->handle($chair);

    // An opened round takes no candidacy, by the policy and by the model.
    expect(fn () => app(Candidacies::class)->submit($chair->fresh(), electionVoter()))->toThrow(AuthorizationException::class)
        ->and(fn () => runOffChainStand($chair->fresh(), (int) electionVoter()->id))->toThrow(ElectionRoundStateException::class);

    // The earlier draft takes A's candidacy and its approval, then refuses to open.
    $candidacy = app(Candidacies::class)->submit($treasurer->fresh(), $a);
    app(Candidacies::class)->approve($candidacy, $boardMember);

    expect($candidacy->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(runOffChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('open elsewhere')
        ->and($treasurer->fresh()->opened_at)->toBeNull();
});
