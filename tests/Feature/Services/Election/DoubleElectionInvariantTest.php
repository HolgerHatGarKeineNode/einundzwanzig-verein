<?php

/*
 * Nobody is elected twice in one election, as an invariant: „Wer in einem
 * Wahlgang gewählt ist, steht in den folgenden Wahlgängen nicht mehr zur
 * Wahl.“ (Wahlreglement § 3 Nr. 7).
 *
 * Two contests of one election: „Vorsitz“ (T), sealed in a tie of A, B and C
 * for two seats that waits for the chair's casting vote (statutes Art. 6),
 * and „Kasse“ (K), where A stands against D. The matrix runs every state of
 * K — draft, opened and not sealed, sealed with A elected, sealed with A not
 * elected, annulled — in both orders of the two „elect“ events (the casting
 * vote first, or K sealed first), with A at the lowest and at the highest
 * candidate id among the two the chair names.
 *
 * Every cell asserts that every member of the election is elected in at most
 * one round, and the exact refusal — class, member, round and message — at
 * the layer that refuses: `RecordCastingVote` or `OpenRound`. States are
 * reached through the services only; no row is written past them.
 *
 * Cells that cannot be reached, and why:
 *  - K opened before the tie of T exists: T cannot be opened while A stands
 *    in the opened K, and K cannot be opened while A stands in the opened T
 *    (`OpenRound`, „also stands in the opened round“).
 *  - K opened while the tie of T is undecided and has no run-off: refused by
 *    `OpenRound` („undecided tie of the round "Vorsitz"“). The only way in is
 *    a run-off of T that is still a draft, which is the way every opened
 *    state below takes.
 *  - the casting vote first, then K opened, sealed or annulled with A on its
 *    ballot: once the casting vote elects A, `OpenRound` refuses K — the
 *    „casting vote first“ cell pins that refusal, which stands for all of
 *    those target states.
 */

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionCastingVote;
use App\Models\ElectionRound;
use App\Services\Election\Candidacies;
use App\Services\Election\CloseRound;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\OpenRound;
use App\Services\Election\RecordCastingVote;
use App\Services\Election\TallyRound;

require_once __DIR__.'/helpers.php';

const INVARIANT_OPENED_REFUSAL = 'The chair cannot decide for a candidate who also stands in the opened round "Kasse" of the election, which is not sealed, so nobody knows yet whether they are elected there; decide for another candidate level in the tie, or seal that round first.';

const INVARIANT_ELECTED_REFUSAL = 'The chair cannot decide for a candidate who is elected in the round "Kasse" of the election already; decide for another candidate level in the tie.';

const INVARIANT_OPEN_REFUSAL = 'A candidate of this round has been elected in an earlier round of the election.';

/**
 * What an action did: 'ok', or the refusal as [kind, member, round, message].
 *
 * @return 'ok'|array{0: string, 1: ?int, 2: ?string, 3: string}
 */
function invariantOutcome(Closure $act): string|array
{
    try {
        $act();
    } catch (ElectionCandidateBlockedException $refusal) {
        return ['blocked', $refusal->memberId, $refusal->roundTitle, $refusal->getMessage()];
    } catch (ElectionActionRefusedException|ElectionRoundStateException $refusal) {
        return ['refused', null, null, $refusal->getMessage()];
    }

    return 'ok';
}

/**
 * The member stands in the draft round: submitted by the member, approved by the board.
 */
function invariantStand(ElectionRound $round, EinundzwanzigPleb $member, EinundzwanzigPleb $board): ElectionCandidate
{
    $candidacy = app(Candidacies::class)->submit($round->fresh(), $member);

    return app(Candidacies::class)->approve($candidacy, $board);
}

function invariantCandidate(ElectionRound $round, EinundzwanzigPleb $member): ElectionCandidate
{
    return $round->candidates()->where('einundzwanzig_pleb_id', $member->id)->firstOrFail();
}

/**
 * The chair's casting vote on the tie of „Vorsitz“ for the given members.
 *
 * @param  list<EinundzwanzigPleb>  $members
 * @return 'ok'|array{0: string, 1: ?int, 2: ?string, 3: string}
 */
function invariantCast(ElectionRound $vorsitz, EinundzwanzigPleb $board, array $members): string|array
{
    return invariantOutcome(fn () => app(RecordCastingVote::class)->handle(
        $vorsitz->fresh(),
        $board,
        array_map(fn (EinundzwanzigPleb $member): int => (int) invariantCandidate($vorsitz, $member)->id, $members),
        'Erika',
    ));
}

/**
 * Each member of the election mapped to the titles of the sealed rounds
 * whose effective result elects them.
 *
 * @return array<int, list<string>>
 */
function invariantElected(Election $election): array
{
    $elected = [];

    foreach (ElectionRound::query()->where('election_event_id', $election->id)->with('candidates')->get() as $round) {
        $winners = $round->isSealed() ? app(TallyRound::class)->effective($round)->elected : [];

        foreach ($round->candidates as $candidate) {
            $elected[(int) $candidate->einundzwanzig_pleb_id] ??= [];

            if (in_array((int) $candidate->id, $winners, true)) {
                $elected[(int) $candidate->einundzwanzig_pleb_id][] = $round->title;
            }
        }
    }

    return $elected;
}

/**
 * The invariant: every member of the election is elected in at most one round.
 */
function expectNobodyElectedTwice(Election $election): void
{
    $elected = invariantElected($election);

    expect($elected)->not->toBeEmpty();

    foreach ($elected as $member => $titles) {
        expect(count($titles))->toBeLessThanOrEqual(1, "member {$member} is elected in: ".implode(', ', $titles));
    }
}

/**
 * „Kasse“ (position 1, draft, one seat; A and D stand) and „Vorsitz“
 * (position 2, two seats, sealed): A, B and C two votes each, a tie of three
 * for two seats. The chair names A together with B; `$chosenAt` puts A's
 * candidacy in „Vorsitz“ at the lowest or the highest id of the two.
 *
 * @return array{board: EinundzwanzigPleb, election: Election, kasse: ElectionRound, vorsitz: ElectionRound, a: EinundzwanzigPleb, b: EinundzwanzigPleb, d: EinundzwanzigPleb, chosen: list<EinundzwanzigPleb>, voters: list<EinundzwanzigPleb>}
 */
function invariantEvening(string $chosenAt): array
{
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $kasse = draftElectionRound(['position' => 1, 'title' => 'Kasse', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: 0, election: $election);
    $vorsitz = draftElectionRound(['position' => 2, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 0, election: $election);

    // Created in this order, so the candidate ids in „Vorsitz“ run low, mid, high.
    [$low, $mid, $high] = [electionVoter(), electionVoter(), electionVoter()];

    foreach ([$low, $mid, $high] as $member) {
        invariantStand($vorsitz, $member, $board);
    }

    $voters = [electionVoter(), electionVoter(), electionVoter()];
    $vorsitz = app(OpenRound::class)->handle($vorsitz->fresh());

    foreach ([[$low, $mid], [$mid, $high], [$high, $low]] as $index => $pair) {
        castElectionBallot($vorsitz, $voters[$index], [
            invariantCandidate($vorsitz, $pair[0])->id => 'for',
            invariantCandidate($vorsitz, $pair[1])->id => 'for',
        ]);
    }

    $vorsitz = app(CloseRound::class)->handle($vorsitz);
    $tie = app(TallyRound::class)->effective($vorsitz);
    expect($tie->tiedSeats)->toBe(2)->and($tie->tied)->toHaveCount(3);

    $a = $chosenAt === 'lowest' ? $low : $high;
    $chosen = $chosenAt === 'lowest' ? [$low, $mid] : [$mid, $high];
    $ids = array_map(fn (EinundzwanzigPleb $member): int => (int) invariantCandidate($vorsitz, $member)->id, $chosen);
    expect((int) invariantCandidate($vorsitz, $a)->id)->toBe($chosenAt === 'lowest' ? min($ids) : max($ids));

    $d = electionVoter();
    invariantStand($kasse, $a, $board);
    invariantStand($kasse, $d, $board);

    return ['board' => $board, 'election' => $election, 'kasse' => $kasse->fresh(), 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $mid, 'd' => $d, 'chosen' => $chosen, 'voters' => $voters];
}

/**
 * K opened with A on its ballot, the only way there is: the assembly sends
 * the tie of „Vorsitz“ to a run-off, K is opened while that run-off is a
 * draft, two votes go to `$winner` in K, and the run-off — A withdrawn from
 * it — is opened and annulled, which hands the tie back to the chair.
 *
 * @param  array<string, mixed>  $evening
 */
function invariantOpenKasse(array $evening, EinundzwanzigPleb $winner): ElectionRound
{
    ['board' => $board, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;

    $runOff = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    $voters = [electionVoter(), electionVoter()];
    $kasse = app(OpenRound::class)->handle($kasse->fresh());

    foreach ($voters as $voter) {
        castElectionBallot($kasse, $voter, [invariantCandidate($kasse, $winner)->id => 'for']);
    }

    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'steht in der Kasse');
    $runOff = app(OpenRound::class)->handle($runOff->fresh());
    app(ElectionStewardship::class)->annul($runOff, $board, 'zurück an die Vorsitzende');

    expect($kasse->fresh()->opened_at)->not->toBeNull()
        ->and($kasse->fresh()->closed_at)->toBeNull()
        ->and($runOff->fresh()->annulled_at)->not->toBeNull();

    return $kasse->fresh();
}

dataset('chosen position', ['A lowest id' => 'lowest', 'A highest id' => 'highest']);

it('the reproduction of gate finding D2: a casting vote for A, who stands in the opened Kasse, is refused, and Kasse sealed elects A once', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $kasse = draftElectionRound(['position' => 1, 'title' => 'Kasse', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $election);
    $vorsitz = draftElectionRound(['position' => 2, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $election);
    [$a, $b] = [electionVoter(), electionVoter()];
    invariantStand($vorsitz, $a, $board);
    invariantStand($vorsitz, $b, $board);
    $voters = [electionVoter(), electionVoter()];
    $vorsitz = app(OpenRound::class)->handle($vorsitz->fresh());
    castElectionBallot($vorsitz, $voters[0], [invariantCandidate($vorsitz, $a)->id => 'for']);
    castElectionBallot($vorsitz, $voters[1], [invariantCandidate($vorsitz, $b)->id => 'for']);
    $vorsitz = app(CloseRound::class)->handle($vorsitz);

    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    invariantStand($kasse, $a, $board);
    $kasseVoter = electionVoter();
    $kasse = app(OpenRound::class)->handle($kasse->fresh());
    castElectionBallot($kasse, $kasseVoter, [invariantCandidate($kasse, $a)->id => 'for']);
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'x');
    $runOff = app(OpenRound::class)->handle($runOff->fresh());
    app(ElectionStewardship::class)->annul($runOff, $board, 'falsch');

    expect(invariantCast($vorsitz, $board, [$a]))->toBe(['blocked', $a->id, 'Kasse', INVARIANT_OPENED_REFUSAL]);

    app(CloseRound::class)->handle($kasse->fresh());

    expect(invariantElected($election)[$a->id])->toBe(['Kasse'])
        ->and(invariantCast($vorsitz, $board, [$a]))->toBe(['blocked', $a->id, 'Kasse', INVARIANT_ELECTED_REFUSAL])
        ->and(invariantCast($vorsitz, $board, [$b]))->toBe('ok')
        ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
});

it('K draft, the casting vote first: it elects A, and OpenRound then refuses K for every later state', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and(invariantOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toBe(['blocked', $a->id, 'Vorsitz', INVARIANT_OPEN_REFUSAL])
        ->and($kasse->fresh()->opened_at)->toBeNull()
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K struck while a draft: the casting vote for A is taken', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;

    app(ElectionStewardship::class)->strike($kasse, $board, 'kein Bedarf');

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K opened and not sealed: RecordCastingVote refuses the casting vote for A, naming A and Kasse, and takes the other candidates', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $b, 'd' => $d] = $evening;
    $kasse = invariantOpenKasse($evening, $a);
    $free = array_values(array_filter(app(TallyRound::class)->effective($vorsitz)->tied, fn (int $id): bool => ! in_array($id, [invariantCandidate($vorsitz, $a)->id], true)));

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', INVARIANT_OPENED_REFUSAL])
        ->and($kasse->fresh()->closed_at)->toBeNull();
    expectNobodyElectedTwice($election);

    expect(invariantOutcome(fn () => app(RecordCastingVote::class)->handle($vorsitz->fresh(), $board, $free, 'Erika')))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe([])
        ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K opened, A withdrawn there before it opened: the casting vote for A is taken', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;

    app(Candidacies::class)->withdraw(invariantCandidate($kasse, $a), $board, 'tritt im Vorsitz an');
    $kasse = app(OpenRound::class)->handle($kasse->fresh());

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and($kasse->fresh()->closed_at)->toBeNull()
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K sealed with A elected, K first: RecordCastingVote refuses the casting vote for A, naming A and Kasse', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;
    $kasse = invariantOpenKasse($evening, $a);

    app(CloseRound::class)->handle($kasse);

    expect(invariantElected($election)[$a->id])->toBe(['Kasse'])
        ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', INVARIANT_ELECTED_REFUSAL]);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K sealed with A elected, the casting vote first: refused while K is open, and refused again once K elected A', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;
    $kasse = invariantOpenKasse($evening, $a);

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', INVARIANT_OPENED_REFUSAL]);

    app(CloseRound::class)->handle($kasse);

    expect(invariantElected($election)[$a->id])->toBe(['Kasse'])
        ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', INVARIANT_ELECTED_REFUSAL]);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K sealed with A not elected, K first: the casting vote for A is taken', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a, 'd' => $d] = $evening;
    $kasse = invariantOpenKasse($evening, $d);

    app(CloseRound::class)->handle($kasse);

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$d->id])->toBe(['Kasse']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K sealed with A not elected, the casting vote first: refused while K is open, taken once K is sealed', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a, 'd' => $d] = $evening;
    $kasse = invariantOpenKasse($evening, $d);

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', INVARIANT_OPENED_REFUSAL]);

    app(CloseRound::class)->handle($kasse);

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$d->id])->toBe(['Kasse']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K annulled while open, K first: the casting vote for A is taken', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;
    $kasse = invariantOpenKasse($evening, $a);

    app(ElectionStewardship::class)->annul($kasse, $board, 'falsch');

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K annulled while open, the casting vote first: refused while K is open, taken once K is annulled', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;
    $kasse = invariantOpenKasse($evening, $a);

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', INVARIANT_OPENED_REFUSAL]);

    app(ElectionStewardship::class)->annul($kasse, $board, 'falsch');

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K annulled after it elected A: the casting vote for A is taken', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;
    $kasse = invariantOpenKasse($evening, $a);

    $kasse = app(CloseRound::class)->handle($kasse);
    expect(invariantElected($election)[$a->id])->toBe(['Kasse']);
    app(ElectionStewardship::class)->annul($kasse, $board, 'falsch');

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('A standing in an opened, unsealed round of another election does not hold back the casting vote for A in this one', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $vorsitz = draftElectionRound(['position' => 1, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $election);
    [$a, $b] = [electionVoter(), electionVoter()];
    invariantStand($vorsitz, $a, $board);
    invariantStand($vorsitz, $b, $board);
    $voters = [electionVoter(), electionVoter()];
    $vorsitz = app(OpenRound::class)->handle($vorsitz->fresh());
    castElectionBallot($vorsitz, $voters[0], [invariantCandidate($vorsitz, $a)->id => 'for']);
    castElectionBallot($vorsitz, $voters[1], [invariantCandidate($vorsitz, $b)->id => 'for']);
    $vorsitz = app(CloseRound::class)->handle($vorsitz);

    $other = Election::factory()->create();
    $otherKasse = draftElectionRound(['position' => 1, 'title' => 'Kasse E2', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $other);
    invariantStand($otherKasse, $a, $board);
    $otherKasse = app(OpenRound::class)->handle($otherKasse->fresh());
    expect($otherKasse->opened_at)->not->toBeNull()
        ->and($otherKasse->closed_at)->toBeNull()
        ->and(invariantCandidate($otherKasse, $a)->status)->toBe(ElectionCandidateStatus::Approved);

    expect(invariantCast($vorsitz, $board, [$a]))->toBe('ok')
        ->and(ElectionCastingVote::query()->where('election_round_id', $vorsitz->id)->value('candidate_ids'))->toBe([(int) invariantCandidate($vorsitz, $a)->id])
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
});
