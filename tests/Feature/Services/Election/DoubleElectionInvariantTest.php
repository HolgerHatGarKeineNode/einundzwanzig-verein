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
 *  - one member standing in two opened rounds: `OpenRound` refuses the
 *    second („also stands in the opened round“), so two opened rounds hold
 *    two different chosen members.
 *
 * Every value of each axis of `RecordCastingVote::assertNoneElectedElsewhere()`
 * has a cell that asserts its own state:
 *  - K before the tie: „K opened and not sealed“; K after the tie and
 *    first-level: „K first-level and placed after the tie“.
 *  - K first-level: „K opened and not sealed“; a run-off: „a run-off of K“.
 *  - K live: „K opened and not sealed“; a window over by the clock: „K a
 *    window round“.
 *  - one opened round holding a chosen member: „K opened and not sealed“;
 *    two, the earliest by position with the higher id: „two opened rounds“.
 *  - A approved in the opened K: „K opened and not sealed“; pending or
 *    rejected: „A only a pending or rejected candidate“; withdrawn: „A
 *    withdrawn there before it opened“, and not named: „B withdrawn there at
 *    a lower candidate id“.
 *  - sealed K with one seat: „K sealed with A elected, K first“; two seats
 *    with A the first or the second winner: „K with two seats“.
 */

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionRoundMode;
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
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/helpers.php';

const INVARIANT_OPENED_REFUSAL = 'The chair cannot decide for a candidate who also stands in the opened round "Kasse" of the election, which is not sealed, so nobody knows yet whether they are elected there; decide for another candidate level in the tie, or seal that round first.';

const INVARIANT_ELECTED_REFUSAL = 'The chair cannot decide for a candidate who is elected in the round "Kasse" of the election already; decide for another candidate level in the tie.';

/**
 * `RecordCastingVote`'s refusal for a member standing in the opened, unsealed round `$title`.
 */
function invariantOpenedRefusal(string $title): string
{
    return str_replace('"Kasse"', '"'.$title.'"', INVARIANT_OPENED_REFUSAL);
}

/**
 * `RecordCastingVote`'s refusal for a member elected in the sealed round `$title`.
 */
function invariantElectedRefusal(string $title): string
{
    return str_replace('"Kasse"', '"'.$title.'"', INVARIANT_ELECTED_REFUSAL);
}

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
 * Open `$round` through `OpenRound`, asserting that it opens: a setup step
 * that a broken guard refuses fails the cell by assertion, not by an
 * exception.
 */
function invariantOpen(ElectionRound $round): ElectionRound
{
    $opened = null;
    expect(invariantOutcome(function () use ($round, &$opened): void {
        $opened = app(OpenRound::class)->handle($round->fresh());
    }))->toBe('ok', "opening the round \"{$round->title}\"");

    return $opened;
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

    // Counted independently of invariantElected(): every member who stands
    // anywhere in the election is in the map, so no round was skipped.
    $standing = ElectionCandidate::query()
        ->whereIn('election_round_id', ElectionRound::query()->where('election_event_id', $election->id)->select('id'))
        ->distinct()
        ->pluck('einundzwanzig_pleb_id')
        ->map(fn (mixed $id): int => (int) $id)
        ->sort()
        ->values()
        ->all();
    expect(collect(array_keys($elected))->sort()->values()->all())->toBe($standing);

    foreach ($elected as $member => $titles) {
        expect(count($titles))->toBeLessThanOrEqual(1, "member {$member} is elected in: ".implode(', ', $titles));
    }
}

/**
 * Seal the draft `$vorsitz` (two seats) in a tie of three: low, mid and high
 * stand in this order, so their candidate ids run low, mid, high, and three
 * voters give each of them two votes.
 *
 * @return array{0: ElectionRound, 1: list<EinundzwanzigPleb>, 2: list<EinundzwanzigPleb>}
 */
function invariantTieOfThree(ElectionRound $vorsitz, EinundzwanzigPleb $board): array
{
    [$low, $mid, $high] = [electionVoter(), electionVoter(), electionVoter()];

    foreach ([$low, $mid, $high] as $member) {
        invariantStand($vorsitz, $member, $board);
    }

    $voters = [electionVoter(), electionVoter(), electionVoter()];
    $vorsitz = invariantOpen($vorsitz->fresh());

    foreach ([[$low, $mid], [$mid, $high], [$high, $low]] as $index => $pair) {
        castElectionBallot($vorsitz, $voters[$index], [
            invariantCandidate($vorsitz, $pair[0])->id => 'for',
            invariantCandidate($vorsitz, $pair[1])->id => 'for',
        ]);
    }

    $vorsitz = app(CloseRound::class)->handle($vorsitz);
    $tie = app(TallyRound::class)->effective($vorsitz);
    expect($vorsitz->seats)->toBe(2)
        ->and($tie->tiedSeats)->toBe(2)
        ->and($tie->tied)->toBe(array_map(fn (EinundzwanzigPleb $member): int => (int) invariantCandidate($vorsitz, $member)->id, [$low, $mid, $high]));

    return [$vorsitz, [$low, $mid, $high], $voters];
}

/**
 * „Kasse“ (position 1, draft, one seat; A and D stand) and „Vorsitz“
 * (position 2, two seats, sealed): A, B and C two votes each, a tie of three
 * for two seats. The chair names A together with B; `$chosenAt` puts A's
 * candidacy in „Vorsitz“ at the lowest or the highest id of the two.
 * `$kasseAttributes` override those of „Kasse“ (a window round, say);
 * `$bWithdrawnFirstInKasse` gives B a withdrawn candidacy in „Kasse“ with a
 * lower id than A's. `$standInKasse`, given A, D and the board, replaces
 * the approved candidacies of A and D in „Kasse“ (another status for A, or
 * another order).
 *
 * @param  array<string, mixed>  $kasseAttributes
 * @param  (Closure(ElectionRound, EinundzwanzigPleb, EinundzwanzigPleb, EinundzwanzigPleb): void)|null  $standInKasse
 * @return array{board: EinundzwanzigPleb, election: Election, kasse: ElectionRound, vorsitz: ElectionRound, a: EinundzwanzigPleb, b: EinundzwanzigPleb, d: EinundzwanzigPleb, chosen: list<EinundzwanzigPleb>, voters: list<EinundzwanzigPleb>}
 */
function invariantEvening(string $chosenAt, array $kasseAttributes = [], bool $bWithdrawnFirstInKasse = false, ?Closure $standInKasse = null): array
{
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $kasse = draftElectionRound(['position' => 1, 'title' => 'Kasse', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1, ...$kasseAttributes], candidates: 0, election: $election);
    $vorsitz = draftElectionRound(['position' => 2, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 0, election: $election);
    [$vorsitz, [$low, $mid, $high], $voters] = invariantTieOfThree($vorsitz, $board);

    $a = $chosenAt === 'lowest' ? $low : $high;
    $chosen = $chosenAt === 'lowest' ? [$low, $mid] : [$mid, $high];
    $ids = array_map(fn (EinundzwanzigPleb $member): int => (int) invariantCandidate($vorsitz, $member)->id, $chosen);
    expect((int) invariantCandidate($vorsitz, $a)->id)->toBe($chosenAt === 'lowest' ? min($ids) : max($ids));

    $d = electionVoter();

    // B's candidacy in „Kasse“ first, so it has the lower id there; withdrawn while a draft.
    if ($bWithdrawnFirstInKasse) {
        app(Candidacies::class)->withdraw(invariantStand($kasse, $mid, $board), $board, 'tritt nur im Vorsitz an');
    }

    if ($standInKasse !== null) {
        $standInKasse($kasse, $a, $d, $board);
    } else {
        invariantStand($kasse, $a, $board);
        invariantStand($kasse, $d, $board);
    }

    return ['board' => $board, 'election' => $election, 'kasse' => $kasse->fresh(), 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $mid, 'd' => $d, 'chosen' => $chosen, 'voters' => $voters];
}

/**
 * K opened with A on its ballot, the only way there is: the assembly sends
 * the tie of „Vorsitz“ to a run-off, K is opened while that run-off is a
 * draft, two votes go to `$winner` in K (or one ballot per list of members
 * in `$winner`), and the run-off — A withdrawn from it — is opened and
 * annulled, which hands the tie back to the chair.
 *
 * @param  array<string, mixed>  $evening
 * @param  EinundzwanzigPleb|list<list<EinundzwanzigPleb>>  $winner
 */
function invariantOpenKasse(array $evening, EinundzwanzigPleb|array $winner): ElectionRound
{
    ['board' => $board, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;
    $ballots = $winner instanceof EinundzwanzigPleb ? [[$winner], [$winner]] : $winner;

    $runOff = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    $voters = array_map(fn (): EinundzwanzigPleb => electionVoter(), $ballots);
    $kasse = invariantOpen($kasse->fresh());

    foreach ($ballots as $index => $members) {
        castElectionBallot($kasse, $voters[$index], collect($members)->mapWithKeys(fn (EinundzwanzigPleb $member): array => [invariantCandidate($kasse, $member)->id => 'for'])->all());
    }

    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'steht in der Kasse');
    $runOff = invariantOpen($runOff->fresh());
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
    $vorsitz = invariantOpen($vorsitz->fresh());
    castElectionBallot($vorsitz, $voters[0], [invariantCandidate($vorsitz, $a)->id => 'for']);
    castElectionBallot($vorsitz, $voters[1], [invariantCandidate($vorsitz, $b)->id => 'for']);
    $vorsitz = app(CloseRound::class)->handle($vorsitz);

    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    invariantStand($kasse, $a, $board);
    $kasseVoter = electionVoter();
    $kasse = invariantOpen($kasse->fresh());
    castElectionBallot($kasse, $kasseVoter, [invariantCandidate($kasse, $a)->id => 'for']);
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'x');
    $runOff = invariantOpen($runOff->fresh());
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

    expect($kasse->position)->toBeLessThan($vorsitz->position)
        ->and($kasse->parent_round_id)->toBeNull()
        ->and($kasse->mode)->toBe(ElectionRoundMode::Live)
        ->and($kasse->closes_at->isFuture())->toBeTrue()
        ->and($kasse->seats)->toBe(1)
        ->and(invariantCandidate($kasse, $a)->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(ElectionRound::query()->where('election_event_id', $election->id)->whereNotNull('opened_at')->whereNull('closed_at')->whereNull('annulled_at')->pluck('title')->all())->toBe(['Kasse']);

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
    $kasse = invariantOpen($kasse->fresh());

    expect($kasse->opened_at)->not->toBeNull()
        ->and($kasse->position)->toBeLessThan($vorsitz->position)
        ->and(invariantCandidate($kasse, $a)->status)->toBe(ElectionCandidateStatus::Withdrawn);

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and($kasse->fresh()->closed_at)->toBeNull()
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K sealed with A elected, K first: RecordCastingVote refuses the casting vote for A, naming A and Kasse', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;
    $kasse = invariantOpenKasse($evening, $a);

    $kasse = app(CloseRound::class)->handle($kasse);

    expect($kasse->seats)->toBe(1)
        ->and(app(TallyRound::class)->effective($kasse)->elected)->toBe([(int) invariantCandidate($kasse, $a)->id])
        ->and(invariantElected($election)[$a->id])->toBe(['Kasse'])
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
    $vorsitz = invariantOpen($vorsitz->fresh());
    castElectionBallot($vorsitz, $voters[0], [invariantCandidate($vorsitz, $a)->id => 'for']);
    castElectionBallot($vorsitz, $voters[1], [invariantCandidate($vorsitz, $b)->id => 'for']);
    $vorsitz = app(CloseRound::class)->handle($vorsitz);

    $other = Election::factory()->create();
    $otherKasse = draftElectionRound(['position' => 1, 'title' => 'Kasse E2', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $other);
    invariantStand($otherKasse, $a, $board);
    expect(invariantOutcome(fn () => app(OpenRound::class)->handle($otherKasse->fresh())))->toBe('ok');
    $otherKasse = $otherKasse->fresh();
    expect($otherKasse->opened_at)->not->toBeNull()
        ->and($otherKasse->closed_at)->toBeNull()
        ->and(invariantCandidate($otherKasse, $a)->status)->toBe(ElectionCandidateStatus::Approved);

    expect(invariantCast($vorsitz, $board, [$a]))->toBe('ok')
        ->and(ElectionCastingVote::query()->where('election_round_id', $vorsitz->id)->value('candidate_ids'))->toBe([(int) invariantCandidate($vorsitz, $a)->id])
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
});

it('K a window round, over by the clock and not sealed: RecordCastingVote refuses the casting vote for A, and K sealed elects A once', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt, ['mode' => ElectionRoundMode::Window, 'opens_at' => now()->addHour(), 'closes_at' => now()->addHours(2)]);
    ['board' => $board, 'election' => $election, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a] = $evening;

    $runOff = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    $voters = [electionVoter(), electionVoter()];
    $kasse = invariantOpen($kasse->fresh());
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'steht in der Kasse');
    app(ElectionStewardship::class)->annul(invariantOpen($runOff->fresh()), $board, 'zurück an die Vorsitzende');

    $this->travel(90)->minutes();
    foreach ($voters as $voter) {
        castElectionBallot($kasse->fresh(), $voter, [invariantCandidate($kasse, $a)->id => 'for']);
    }
    $this->travel(2)->hours();

    $kasse = $kasse->fresh();
    expect($kasse->mode)->toBe(ElectionRoundMode::Window)
        ->and($kasse->parent_round_id)->toBeNull()
        ->and($kasse->position)->toBeLessThan($vorsitz->fresh()->position)
        ->and($kasse->opened_at)->not->toBeNull()
        ->and(invariantCandidate($kasse, $a)->status)->toBe(ElectionCandidateStatus::Approved)
        ->and($kasse->closes_at->isPast())->toBeTrue()
        ->and($kasse->closed_at)->toBeNull()
        ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', invariantOpenedRefusal('Kasse')]);

    app(CloseRound::class)->handle($kasse);

    expect(invariantElected($election)[$a->id])->toBe(['Kasse'])
        ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', invariantElectedRefusal('Kasse')]);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('a run-off of K, placed after the tie, opened and not sealed: RecordCastingVote refuses the casting vote for A, and the run-off elects A once', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt);
    ['board' => $board, 'election' => $election, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a, 'd' => $d] = $evening;

    $runOffV = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    $voters = [electionVoter(), electionVoter()];
    $kasse = invariantOpen($kasse->fresh());
    castElectionBallot($kasse, $voters[0], [invariantCandidate($kasse, $a)->id => 'for']);
    castElectionBallot($kasse, $voters[1], [invariantCandidate($kasse, $d)->id => 'for']);
    $kasse = app(CloseRound::class)->handle($kasse);
    expect(app(TallyRound::class)->effective($kasse)->tied)->toHaveCount(2);

    $runOffK = invariantOpen(app(CreateRunOff::class)->handle($kasse, $board, resolvedByAssembly: true));
    expect($runOffK->title)->toBe('Stichwahl Kasse')
        ->and($runOffK->parent_round_id)->toBe($kasse->id)
        ->and($runOffK->mode)->toBe(ElectionRoundMode::Live)
        ->and($runOffK->opened_at)->not->toBeNull()
        ->and($runOffK->closed_at)->toBeNull()
        ->and(invariantCandidate($runOffK, $a)->status)->toBe(ElectionCandidateStatus::Approved)
        ->and($runOffK->position)->toBeGreaterThan($vorsitz->fresh()->position);

    app(Candidacies::class)->withdraw(invariantCandidate($runOffV, $a), $board, 'steht in der Stichwahl Kasse');
    app(ElectionStewardship::class)->annul(invariantOpen($runOffV->fresh()), $board, 'zurück an die Vorsitzende');

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Stichwahl Kasse', invariantOpenedRefusal('Stichwahl Kasse')]);

    castElectionBallot($runOffK, $voters[0], [invariantCandidate($runOffK, $a)->id => 'for']);
    app(CloseRound::class)->handle($runOffK);

    expect(invariantElected($election)[$a->id])->toBe(['Stichwahl Kasse'])
        ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Stichwahl Kasse', invariantElectedRefusal('Stichwahl Kasse')]);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K opened with B withdrawn there at a lower candidate id: the refusal names A, who stands, not B', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt, bWithdrawnFirstInKasse: true);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $b] = $evening;
    $kasse = invariantOpenKasse($evening, $a);

    expect(invariantCandidate($kasse, $b)->id)->toBeLessThan(invariantCandidate($kasse, $a)->id)
        ->and(invariantCandidate($kasse, $b)->status)->toBe(ElectionCandidateStatus::Withdrawn)
        ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', invariantOpenedRefusal('Kasse')]);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('K first-level and placed after the tie, opened once a run-off of the tie was sealed, the run-off then annulled: RecordCastingVote refuses the casting vote for A, and K sealed elects A once', function (string $chosenAt) {
    $evening = invariantEvening($chosenAt, ['position' => 3]);
    ['board' => $board, 'election' => $election, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $b, 'voters' => $voters] = $evening;

    // K comes after the tie, so OpenRound refuses it while the run-off is a
    // draft; it opens once the run-off decided the tie, and the annulled
    // run-off hands the tie back to the chair.
    $runOff = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'steht in der Kasse');
    $runOff = invariantOpen($runOff->fresh());
    $standing = $runOff->candidates()->where('status', ElectionCandidateStatus::Approved)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    castElectionBallot($runOff, $voters[0], array_fill_keys($standing, 'for'));
    $runOff = app(CloseRound::class)->handle($runOff);
    expect($standing)->toHaveCount(2)
        ->and(app(TallyRound::class)->effective($runOff)->elected)->toBe($standing);

    $kasseVoter = electionVoter();
    $kasse = invariantOpen($kasse->fresh());
    castElectionBallot($kasse, $kasseVoter, [invariantCandidate($kasse, $a)->id => 'for']);
    app(ElectionStewardship::class)->annul($runOff->fresh(), $board, 'zurück an die Vorsitzende');

    $kasse = $kasse->fresh();
    $vorsitz = $vorsitz->fresh();
    expect($kasse->position)->toBeGreaterThan($vorsitz->position)
        ->and($kasse->parent_round_id)->toBeNull()
        ->and($vorsitz->parent_round_id)->toBeNull()
        ->and($kasse->mode)->toBe(ElectionRoundMode::Live)
        ->and($kasse->closes_at->isFuture())->toBeTrue()
        ->and($kasse->opened_at)->not->toBeNull()
        ->and($kasse->closed_at)->toBeNull()
        ->and($kasse->annulled_at)->toBeNull()
        ->and(invariantCandidate($kasse, $a)->status)->toBe(ElectionCandidateStatus::Approved)
        ->and($runOff->fresh()->annulled_at)->not->toBeNull()
        ->and(app(TallyRound::class)->effective($vorsitz)->tiedSeats)->toBe(2);

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', invariantOpenedRefusal('Kasse')])
        ->and(ElectionCastingVote::query()->where('election_round_id', $vorsitz->id)->exists())->toBeFalse();

    app(CloseRound::class)->handle($kasse);
    $free = array_values(array_diff(app(TallyRound::class)->effective($vorsitz)->tied, [(int) invariantCandidate($vorsitz, $a)->id]));

    expect(invariantElected($election)[$a->id])->toBe(['Kasse'])
        ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', invariantElectedRefusal('Kasse')])
        ->and(invariantOutcome(fn () => app(RecordCastingVote::class)->handle($vorsitz->fresh(), $board, $free, 'Erika')))->toBe('ok')
        ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position');

it('two opened rounds hold a chosen member each, the earliest by position with the higher id: the refusal names that round and its member, then the other, and the casting vote is taken once both are sealed without them', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    // Created first, placed second: „Kasse“ has the lower id, „Beisitz“ the earlier position.
    $kasse = draftElectionRound(['position' => 2, 'title' => 'Kasse', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: 0, election: $election);
    $beisitz = draftElectionRound(['position' => 1, 'title' => 'Beisitz', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: 0, election: $election);
    $vorsitz = draftElectionRound(['position' => 3, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 0, election: $election);
    [$vorsitz, [$a, $b], $voters] = invariantTieOfThree($vorsitz, $board);
    [$d, $e] = [electionVoter(), electionVoter()];
    invariantStand($kasse, $a, $board);
    invariantStand($kasse, $d, $board);
    invariantStand($beisitz, $b, $board);
    invariantStand($beisitz, $e, $board);

    $runOff = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    $roundVoter = electionVoter();
    $kasse = invariantOpen($kasse->fresh());
    $this->travel(1)->minutes();
    $beisitz = invariantOpen($beisitz->fresh());
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'steht in der Kasse');
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $b), $board, 'steht im Beisitz');
    app(ElectionStewardship::class)->annul(invariantOpen($runOff->fresh()), $board, 'zurück an die Vorsitzende');

    [$kasse, $beisitz, $vorsitz] = [$kasse->fresh(), $beisitz->fresh(), $vorsitz->fresh()];
    expect($beisitz->position)->toBeLessThan($kasse->position)
        ->and($kasse->position)->toBeLessThan($vorsitz->position)
        ->and($beisitz->id)->toBeGreaterThan($kasse->id)
        ->and($beisitz->opened_at->isAfter($kasse->opened_at))->toBeTrue()
        ->and([$kasse->parent_round_id, $beisitz->parent_round_id])->toBe([null, null])
        ->and([$kasse->closed_at, $beisitz->closed_at, $kasse->annulled_at, $beisitz->annulled_at])->toBe([null, null, null, null])
        ->and(invariantCandidate($kasse, $a)->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(invariantCandidate($beisitz, $b)->status)->toBe(ElectionCandidateStatus::Approved)
        ->and($runOff->fresh()->annulled_at)->not->toBeNull();

    expect(invariantCast($vorsitz, $board, [$a, $b]))->toBe(['blocked', $b->id, 'Beisitz', invariantOpenedRefusal('Beisitz')]);

    castElectionBallot($beisitz, $roundVoter, [invariantCandidate($beisitz, $e)->id => 'for']);
    app(CloseRound::class)->handle($beisitz);

    expect(invariantElected($election)[$b->id])->toBe([])
        ->and(invariantCast($vorsitz, $board, [$a, $b]))->toBe(['blocked', $a->id, 'Kasse', invariantOpenedRefusal('Kasse')]);

    castElectionBallot($kasse, $roundVoter, [invariantCandidate($kasse, $d)->id => 'for']);
    app(CloseRound::class)->handle($kasse);

    expect(invariantCast($vorsitz, $board, [$a, $b]))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$d->id])->toBe(['Kasse'])
        ->and(invariantElected($election)[$e->id])->toBe(['Beisitz']);
    expectNobodyElectedTwice($election);
});

it('K opened with A only a pending or rejected candidate there: the casting vote for A is taken, A cannot be put on the ballot of the opened K, and K sealed elects D', function (ElectionCandidateStatus $status) {
    $evening = invariantEvening('highest', standInKasse: function (ElectionRound $kasse, EinundzwanzigPleb $a, EinundzwanzigPleb $d, EinundzwanzigPleb $board) use ($status): void {
        $candidacy = app(Candidacies::class)->submit($kasse->fresh(), $a);

        if ($status === ElectionCandidateStatus::Rejected) {
            app(Candidacies::class)->reject($candidacy, $board, 'tritt im Vorsitz an');
        }

        invariantStand($kasse, $d, $board);
    });
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a, 'd' => $d] = $evening;
    $kasse = invariantOpenKasse($evening, $d);

    expect($kasse->position)->toBeLessThan($vorsitz->position)
        ->and($kasse->parent_round_id)->toBeNull()
        ->and($kasse->opened_at)->not->toBeNull()
        ->and($kasse->closed_at)->toBeNull()
        ->and(invariantCandidate($kasse, $a)->status)->toBe($status)
        ->and(invariantCandidate($kasse, $d)->status)->toBe(ElectionCandidateStatus::Approved);

    $chosenIds = array_map(fn (EinundzwanzigPleb $member): int => (int) invariantCandidate($vorsitz, $member)->id, $evening['chosen']);
    sort($chosenIds);

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
        ->and(ElectionCastingVote::query()->where('election_round_id', $vorsitz->id)->value('candidate_ids'))->toBe($chosenIds)
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz']);

    expect(fn () => app(Candidacies::class)->approve(invariantCandidate($kasse, $a), $board))->toThrow(AuthorizationException::class);
    expect(invariantCandidate($kasse, $a)->status)->toBe($status);

    app(CloseRound::class)->handle($kasse->fresh());

    expect(invariantElected($election)[$a->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$d->id])->toBe(['Kasse']);
    expectNobodyElectedTwice($election);
})->with(['pending' => ElectionCandidateStatus::Pending, 'rejected' => ElectionCandidateStatus::Rejected]);

it('K with two seats, sealed with A elected as the first or the second winner: RecordCastingVote refuses the casting vote for A, naming A and Kasse, and takes the others', function (string $chosenAt, string $rank) {
    $f = electionVoter();
    $evening = invariantEvening($chosenAt, ['seats' => 2], standInKasse: function (ElectionRound $kasse, EinundzwanzigPleb $a, EinundzwanzigPleb $d, EinundzwanzigPleb $board) use ($rank, $f): void {
        foreach ($rank === 'first' ? [$a, $d, $f] : [$d, $a, $f] as $member) {
            invariantStand($kasse, $member, $board);
        }
    });
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $b, 'd' => $d] = $evening;
    // A and D win, F gets no vote; the winners are listed by candidate id.
    $kasse = invariantOpenKasse($evening, [[$a, $d], [$rank === 'first' ? $a : $d]]);
    $kasse = app(CloseRound::class)->handle($kasse);
    [$aInKasse, $dInKasse] = [(int) invariantCandidate($kasse, $a)->id, (int) invariantCandidate($kasse, $d)->id];

    expect($kasse->seats)->toBe(2)
        ->and($kasse->parent_round_id)->toBeNull()
        ->and($kasse->closed_at)->not->toBeNull()
        ->and($kasse->annulled_at)->toBeNull()
        ->and(app(TallyRound::class)->effective($kasse)->elected)->toBe($rank === 'first' ? [$aInKasse, $dInKasse] : [$dInKasse, $aInKasse]);

    $free = array_values(array_diff(app(TallyRound::class)->effective($vorsitz)->tied, [(int) invariantCandidate($vorsitz, $a)->id]));

    expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', invariantElectedRefusal('Kasse')])
        ->and(invariantOutcome(fn () => app(RecordCastingVote::class)->handle($vorsitz->fresh(), $board, $free, 'Erika')))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Kasse'])
        ->and(invariantElected($election)[$d->id])->toBe(['Kasse'])
        ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz']);
    expectNobodyElectedTwice($election);
})->with('chosen position')->with(['A the first winner' => 'first', 'A the second winner' => 'second']);
