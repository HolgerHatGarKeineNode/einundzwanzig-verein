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
 *  - a run-off of K placed before the tie (level run-off × position before):
 *    a run-off sits before T only if it is created while T is a draft, so K
 *    is sealed in a tie with A level before T opens; that tie belongs to an
 *    earlier contest and stays undecided until the run-off seals, so
 *    `OpenRound` refuses T with A on its ballot. „a run-off of K placed before
 *    the tie cannot hold a chosen member“ pins that refusal.
 *  - a run-off as a window round (level run-off × mode window): `CreateRunOff`
 *    always creates it live.
 *  - A pending or rejected in a run-off (level run-off × status pending,
 *    rejected): `ElectionPolicy::submitCandidacy` refuses a candidacy in a
 *    run-off, `CreateRunOff` copies the tied candidates as approved, and
 *    approve or reject only take a pending candidacy.
 *
 * Every value of each axis of `RecordCastingVote::assertNoneElectedElsewhere()`
 * has a cell that asserts its own state, and so does every reachable pair of
 * values of the axes the opened-round query combines. Axes: P position of
 * the opened round (before or after the tie), L level (first-level or a
 * run-off), M mode (live, or a window over by the clock), S A's candidacy
 * there (approved, pending, rejected, withdrawn), N the number of opened
 * rounds holding a chosen member, O the order of two such rounds by
 * position, id and opening time.
 *  - P × M × S, first-level: „K first-level, opened and not sealed, before or
 *    after the tie, live or a window over by the clock, with A approved,
 *    pending, rejected or withdrawn“ (all 16).
 *  - L × P: first-level × before, after in the cell above; run-off × after in
 *    „a run-off of K placed after the tie“ and „a run-off of K, placed after
 *    the tie, opened“;
 *    run-off × before unreachable (above).
 *  - L × S: first-level × all four above; run-off × approved, withdrawn in „a
 *    run-off of K placed after the tie“; run-off × pending, rejected
 *    unreachable (above).
 *  - L × M: first-level × live, window above; run-off × live in the run-off
 *    cells; run-off × window unreachable (above).
 *  - O × P, N = 2: „two opened rounds hold a chosen member each“ crosses
 *    both before and after the tie with the earliest by position holding the
 *    lower and the higher id, and opened first and last.
 *  - N × S: two opened rounds, the earliest holding B pending, rejected or
 *    withdrawn, in „the earliest by position holding B only pending“.
 *  - O × P, sealed: „two sealed rounds elect a chosen member each“ crosses
 *    before and after the tie with the earliest by position holding the
 *    lower and the higher id.
 *  - sealed K: one seat, K first, in „K sealed with A elected, K first“; two
 *    seats × A the first or the second winner × K before or after the tie in
 *    „K with two seats“; a sealed run-off electing A in „a run-off of K placed
 *    after the tie“.
 *  - not named by A: „B withdrawn there at a lower candidate id“.
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
 * Seal the draft `$vorsitz` (two seats) in a tie of `$count`: the members
 * (`$members`, topped up with new ones) stand in this order, so their
 * candidate ids run in it, and `$count` voters give each of them two votes.
 *
 * @param  list<EinundzwanzigPleb>  $members
 * @return array{0: ElectionRound, 1: list<EinundzwanzigPleb>, 2: list<EinundzwanzigPleb>}
 */
function invariantTie(ElectionRound $vorsitz, EinundzwanzigPleb $board, int $count = 3, array $members = []): array
{
    while (count($members) < $count) {
        $members[] = electionVoter();
    }

    foreach ($members as $member) {
        invariantStand($vorsitz, $member, $board);
    }

    $voters = array_map(fn (): EinundzwanzigPleb => electionVoter(), $members);
    $vorsitz = invariantOpen($vorsitz);

    foreach ($voters as $index => $voter) {
        castElectionBallot($vorsitz, $voter, [
            invariantCandidate($vorsitz, $members[$index])->id => 'for',
            invariantCandidate($vorsitz, $members[($index + 1) % $count])->id => 'for',
        ]);
    }

    $vorsitz = app(CloseRound::class)->handle($vorsitz);
    $tie = app(TallyRound::class)->effective($vorsitz);
    expect($vorsitz->seats)->toBe(2)
        ->and($tie->tiedSeats)->toBe(2)
        ->and($tie->tied)->toBe(array_map(fn (EinundzwanzigPleb $member): int => (int) invariantCandidate($vorsitz, $member)->id, $members));

    return [$vorsitz, $members, $voters];
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
    [$vorsitz, [$low, $mid, $high], $voters] = invariantTie($vorsitz, $board);

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

/**
 * Two opened, unsealed rounds of the election, each holding one chosen
 * member of the tie of four in „Vorsitz“ (A, B, C, E for two seats; the chair
 * names A and B): „Beisitz“, the earlier by position, holds B with
 * `$bStatus`, „Kasse“, the later, holds A approved. `$placement` puts both
 * before the tie (opened while a run-off of the tie is a draft) or both after
 * it (opened once that run-off, A and B withdrawn, sealed C and E). `$idOrder`
 * and `$openedOrder` set whether „Beisitz“ has the lower or the higher id and
 * is opened first or last. The run-off is annulled at the end, which hands the
 * tie back to the chair.
 *
 * @return array{board: EinundzwanzigPleb, election: Election, vorsitz: ElectionRound, beisitz: ElectionRound, kasse: ElectionRound, a: EinundzwanzigPleb, b: EinundzwanzigPleb, x: EinundzwanzigPleb, y: EinundzwanzigPleb, voter: EinundzwanzigPleb}
 */
function invariantTwoOpened(string $placement, string $idOrder, string $openedOrder, ElectionCandidateStatus $bStatus = ElectionCandidateStatus::Approved): array
{
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    [$vorsitzAt, $beisitzAt, $kasseAt] = $placement === 'before' ? [3, 1, 2] : [1, 2, 3];
    $vorsitz = draftElectionRound(['position' => $vorsitzAt, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 0, election: $election);
    $make = fn (string $title, int $position): ElectionRound => draftElectionRound(['position' => $position, 'title' => $title, 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: 0, election: $election);

    if ($idOrder === 'lower id') {
        [$beisitz, $kasse] = [$make('Beisitz', $beisitzAt), $make('Kasse', $kasseAt)];
    } else {
        [$kasse, $beisitz] = [$make('Kasse', $kasseAt), $make('Beisitz', $beisitzAt)];
    }

    [$vorsitz, [$a, $b], $voters] = invariantTie($vorsitz, $board, 4);
    [$x, $y, $voter] = [electionVoter(), electionVoter(), electionVoter()];
    invariantStand($kasse, $a, $board);
    invariantStand($kasse, $y, $board);
    $candidacy = app(Candidacies::class)->submit($beisitz->fresh(), $b);
    match ($bStatus) {
        ElectionCandidateStatus::Approved => app(Candidacies::class)->approve($candidacy, $board),
        ElectionCandidateStatus::Rejected => app(Candidacies::class)->reject($candidacy, $board, 'tritt im Vorsitz an'),
        ElectionCandidateStatus::Withdrawn => app(Candidacies::class)->withdraw($candidacy, $board, 'tritt im Vorsitz an'),
        ElectionCandidateStatus::Pending => $candidacy,
    };
    invariantStand($beisitz, $x, $board);

    $runOff = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'steht in der Kasse');
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $b), $board, 'steht im Beisitz');
    $openBoth = function () use (&$beisitz, &$kasse, $openedOrder): void {
        if ($openedOrder === 'opened first') {
            $beisitz = invariantOpen($beisitz);
            test()->travel(1)->minutes();
            $kasse = invariantOpen($kasse);
        } else {
            $kasse = invariantOpen($kasse);
            test()->travel(1)->minutes();
            $beisitz = invariantOpen($beisitz);
        }
    };

    if ($placement === 'before') {
        $openBoth();
        app(ElectionStewardship::class)->annul(invariantOpen($runOff), $board, 'zurück an die Vorsitzende');
    } else {
        $runOff = invariantOpen($runOff);
        $standing = $runOff->candidates()->where('status', ElectionCandidateStatus::Approved)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        castElectionBallot($runOff, $voters[0], array_fill_keys($standing, 'for'));
        $runOff = app(CloseRound::class)->handle($runOff);
        expect($standing)->toHaveCount(2)
            ->and(app(TallyRound::class)->effective($runOff)->elected)->toBe($standing);
        $openBoth();
        app(ElectionStewardship::class)->annul($runOff->fresh(), $board, 'zurück an die Vorsitzende');
    }

    [$beisitz, $kasse, $vorsitz] = [$beisitz->fresh(), $kasse->fresh(), $vorsitz->fresh()];
    expect($beisitz->position)->toBeLessThan($kasse->position)
        ->and($placement === 'before' ? $kasse->position < $vorsitz->position : $beisitz->position > $vorsitz->position)->toBeTrue()
        ->and($idOrder === 'lower id' ? $beisitz->id < $kasse->id : $beisitz->id > $kasse->id)->toBeTrue()
        ->and($openedOrder === 'opened first' ? $beisitz->opened_at->isBefore($kasse->opened_at) : $beisitz->opened_at->isAfter($kasse->opened_at))->toBeTrue()
        ->and([$kasse->parent_round_id, $beisitz->parent_round_id])->toBe([null, null])
        ->and([$kasse->mode, $beisitz->mode])->toBe([ElectionRoundMode::Live, ElectionRoundMode::Live])
        ->and([$kasse->closed_at, $beisitz->closed_at, $kasse->annulled_at, $beisitz->annulled_at])->toBe([null, null, null, null])
        ->and(invariantCandidate($kasse, $a)->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(invariantCandidate($beisitz, $b)->status)->toBe($bStatus)
        ->and($runOff->fresh()->annulled_at)->not->toBeNull()
        ->and(app(TallyRound::class)->effective($vorsitz)->tied)->toHaveCount(4);

    return ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'beisitz' => $beisitz, 'kasse' => $kasse, 'a' => $a, 'b' => $b, 'x' => $x, 'y' => $y, 'voter' => $voter];
}

it('two opened rounds hold a chosen member each: the refusal names the earliest by position and its member whatever the id and opening order, before and after the tie, then the other, and the casting vote is taken once both are sealed without them', function (string $placement, string $idOrder, string $openedOrder) {
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'beisitz' => $beisitz, 'kasse' => $kasse, 'a' => $a, 'b' => $b, 'x' => $x, 'y' => $y, 'voter' => $voter] = invariantTwoOpened($placement, $idOrder, $openedOrder);

    expect(invariantCast($vorsitz, $board, [$a, $b]))->toBe(['blocked', $b->id, 'Beisitz', invariantOpenedRefusal('Beisitz')]);

    castElectionBallot($beisitz, $voter, [invariantCandidate($beisitz, $x)->id => 'for']);
    app(CloseRound::class)->handle($beisitz);

    expect(invariantElected($election)[$b->id])->toBe([])
        ->and(invariantCast($vorsitz, $board, [$a, $b]))->toBe(['blocked', $a->id, 'Kasse', invariantOpenedRefusal('Kasse')]);

    castElectionBallot($kasse, $voter, [invariantCandidate($kasse, $y)->id => 'for']);
    app(CloseRound::class)->handle($kasse);

    expect(invariantCast($vorsitz, $board, [$a, $b]))->toBe('ok')
        ->and(invariantElected($election)[$a->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$x->id])->toBe(['Beisitz'])
        ->and(invariantElected($election)[$y->id])->toBe(['Kasse']);
    expectNobodyElectedTwice($election);
})->with(['both before the tie' => 'before', 'both after the tie' => 'after'])
    ->with(['Beisitz the lower id' => 'lower id', 'Beisitz the higher id' => 'higher id'])
    ->with(['Beisitz opened first' => 'opened first', 'Beisitz opened last' => 'opened last']);

it('two sealed rounds elect a chosen member each: the refusal names the earliest by position and its member whatever the id order, before and after the tie, and the chair decides for the others', function (string $placement, string $idOrder) {
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'beisitz' => $beisitz, 'kasse' => $kasse, 'a' => $a, 'b' => $b, 'voter' => $voter] = invariantTwoOpened($placement, $idOrder, 'opened first');

    castElectionBallot($beisitz, $voter, [invariantCandidate($beisitz, $b)->id => 'for']);
    castElectionBallot($kasse, $voter, [invariantCandidate($kasse, $a)->id => 'for']);
    [$beisitz, $kasse] = [app(CloseRound::class)->handle($beisitz), app(CloseRound::class)->handle($kasse)];
    $free = array_values(array_diff(app(TallyRound::class)->effective($vorsitz)->tied, [(int) invariantCandidate($vorsitz, $a)->id, (int) invariantCandidate($vorsitz, $b)->id]));

    expect([$beisitz->closed_at, $kasse->closed_at])->each->not->toBeNull()
        ->and(invariantElected($election)[$b->id])->toBe(['Beisitz'])
        ->and(invariantElected($election)[$a->id])->toBe(['Kasse'])
        ->and($free)->toHaveCount(2)
        ->and(invariantCast($vorsitz, $board, [$a, $b]))->toBe(['blocked', $b->id, 'Beisitz', invariantElectedRefusal('Beisitz')])
        ->and(invariantOutcome(fn () => app(RecordCastingVote::class)->handle($vorsitz->fresh(), $board, $free, 'Erika')))->toBe('ok')
        ->and(invariantElected($election)[$b->id])->toBe(['Beisitz'])
        ->and(invariantElected($election)[$a->id])->toBe(['Kasse']);
    expectNobodyElectedTwice($election);
})->with(['both before the tie' => 'before', 'both after the tie' => 'after'])
    ->with(['Beisitz the lower id' => 'lower id', 'Beisitz the higher id' => 'higher id']);

it('two opened rounds, the earliest by position holding B only pending, rejected or withdrawn: the refusal names the later round and A, and the casting vote is taken once it is sealed without A', function (ElectionCandidateStatus $status) {
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'beisitz' => $beisitz, 'kasse' => $kasse, 'a' => $a, 'b' => $b, 'x' => $x, 'y' => $y, 'voter' => $voter] = invariantTwoOpened('before', 'lower id', 'opened first', $status);

    expect(invariantCast($vorsitz, $board, [$a, $b]))->toBe(['blocked', $a->id, 'Kasse', invariantOpenedRefusal('Kasse')]);

    castElectionBallot($kasse, $voter, [invariantCandidate($kasse, $y)->id => 'for']);
    app(CloseRound::class)->handle($kasse);

    expect($beisitz->fresh()->closed_at)->toBeNull()
        ->and(invariantCast($vorsitz, $board, [$a, $b]))->toBe('ok')
        ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz']);

    castElectionBallot($beisitz->fresh(), $voter, [invariantCandidate($beisitz, $x)->id => 'for']);
    app(CloseRound::class)->handle($beisitz->fresh());

    expect(invariantElected($election)[$b->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$x->id])->toBe(['Beisitz']);
    expectNobodyElectedTwice($election);
})->with(['pending' => ElectionCandidateStatus::Pending, 'rejected' => ElectionCandidateStatus::Rejected, 'withdrawn' => ElectionCandidateStatus::Withdrawn]);

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

    // Only a pending candidacy can be approved at all, and only while its
    // round is a draft; a rejected one cannot be approved even then.
    if ($status === ElectionCandidateStatus::Pending) {
        expect(fn () => app(Candidacies::class)->approve(invariantCandidate($kasse, $a), $board))->toThrow(AuthorizationException::class);
    }
    expect(invariantCandidate($kasse, $a)->status)->toBe($status);

    app(CloseRound::class)->handle($kasse->fresh());

    expect(invariantElected($election)[$a->id])->toBe(['Vorsitz'])
        ->and(invariantElected($election)[$d->id])->toBe(['Kasse']);
    expectNobodyElectedTwice($election);
})->with(['pending' => ElectionCandidateStatus::Pending, 'rejected' => ElectionCandidateStatus::Rejected]);

/**
 * K placed after the tie, opened the only way there is: the assembly sends
 * the tie of „Vorsitz“ to a run-off, A is withdrawn from it, it seals B and
 * C, K is opened and gets one ballot per list of members in `$ballots`, and
 * the sealed run-off is annulled, which hands the tie back to the chair.
 *
 * @param  array<string, mixed>  $evening
 * @param  list<list<EinundzwanzigPleb>>  $ballots
 */
function invariantOpenKasseAfterTheTie(array $evening, array $ballots): ElectionRound
{
    ['board' => $board, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a, 'voters' => $voters] = $evening;

    $runOff = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'steht in der Kasse');
    $runOff = invariantOpen($runOff);
    $standing = $runOff->candidates()->where('status', ElectionCandidateStatus::Approved)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    castElectionBallot($runOff, $voters[0], array_fill_keys($standing, 'for'));
    $runOff = app(CloseRound::class)->handle($runOff);
    $kasseVoters = array_map(fn (): EinundzwanzigPleb => electionVoter(), $ballots);
    $kasse = invariantOpen($kasse);

    foreach ($ballots as $index => $members) {
        castElectionBallot($kasse, $kasseVoters[$index], collect($members)->mapWithKeys(fn (EinundzwanzigPleb $member): array => [invariantCandidate($kasse, $member)->id => 'for'])->all());
    }

    app(ElectionStewardship::class)->annul($runOff->fresh(), $board, 'zurück an die Vorsitzende');

    expect($kasse->fresh()->position)->toBeGreaterThan($vorsitz->fresh()->position)
        ->and($runOff->fresh()->annulled_at)->not->toBeNull();

    return $kasse->fresh();
}

it('K with two seats, before or after the tie, sealed with A elected as the first or the second winner: RecordCastingVote refuses the casting vote for A, naming A and Kasse, and takes the others', function (string $chosenAt, string $rank, string $placement) {
    $f = electionVoter();
    $evening = invariantEvening($chosenAt, ['seats' => 2, 'position' => $placement === 'before' ? 1 : 3], standInKasse: function (ElectionRound $kasse, EinundzwanzigPleb $a, EinundzwanzigPleb $d, EinundzwanzigPleb $board) use ($rank, $f): void {
        foreach ($rank === 'first' ? [$a, $d, $f] : [$d, $a, $f] as $member) {
            invariantStand($kasse, $member, $board);
        }
    });
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $b, 'd' => $d] = $evening;
    // A and D win, F gets no vote; the winners are listed by candidate id.
    $ballots = [[$a, $d], [$rank === 'first' ? $a : $d]];
    $kasse = $placement === 'before' ? invariantOpenKasse($evening, $ballots) : invariantOpenKasseAfterTheTie($evening, $ballots);
    $kasse = app(CloseRound::class)->handle($kasse);
    [$aInKasse, $dInKasse] = [(int) invariantCandidate($kasse, $a)->id, (int) invariantCandidate($kasse, $d)->id];

    expect($kasse->seats)->toBe(2)
        ->and($placement === 'before' ? $kasse->position < $vorsitz->fresh()->position : $kasse->position > $vorsitz->fresh()->position)->toBeTrue()
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
})->with('chosen position')->with(['A the first winner' => 'first', 'A the second winner' => 'second'])->with(['K before the tie' => 'before', 'K after the tie' => 'after']);

/**
 * K first-level, opened and not sealed, with A's candidacy there in
 * `$status` and D approved: `$position` 'before' puts K before the tie and
 * opens it while a run-off of the tie is a draft; 'after' puts K after the
 * tie and opens it once that run-off (A withdrawn) sealed B and C. `$mode`
 * 'window' makes K a window round, and the clock runs past its end; 'live'
 * leaves its clock running. Two ballots go to A when approved, to D
 * otherwise. The run-off is annulled, which hands the tie back to the chair.
 *
 * @return array{0: array<string, mixed>, 1: ElectionRound}
 */
function invariantOpenedKasse(string $position, string $mode, ElectionCandidateStatus $status): array
{
    $window = $mode === 'window' ? ['mode' => ElectionRoundMode::Window, 'opens_at' => now()->addHour(), 'closes_at' => now()->addHours(2)] : [];
    $evening = invariantEvening('highest', ['position' => $position === 'before' ? 1 : 3, ...$window], standInKasse: function (ElectionRound $kasse, EinundzwanzigPleb $a, EinundzwanzigPleb $d, EinundzwanzigPleb $board) use ($status): void {
        $candidacy = app(Candidacies::class)->submit($kasse->fresh(), $a);
        match ($status) {
            ElectionCandidateStatus::Approved => app(Candidacies::class)->approve($candidacy, $board),
            ElectionCandidateStatus::Rejected => app(Candidacies::class)->reject($candidacy, $board, 'tritt im Vorsitz an'),
            ElectionCandidateStatus::Withdrawn => app(Candidacies::class)->withdraw($candidacy, $board, 'tritt im Vorsitz an'),
            ElectionCandidateStatus::Pending => $candidacy,
        };
        invariantStand($kasse, $d, $board);
    });
    ['board' => $board, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a, 'd' => $d, 'voters' => $voters] = $evening;
    $winner = $status === ElectionCandidateStatus::Approved ? $a : $d;

    $runOff = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    app(Candidacies::class)->withdraw(invariantCandidate($runOff, $a), $board, 'steht in der Kasse');
    $kasseVoters = [electionVoter(), electionVoter()];

    if ($position === 'before') {
        $kasse = invariantOpen($kasse);
        $runOff = invariantOpen($runOff);
        app(ElectionStewardship::class)->annul($runOff, $board, 'zurück an die Vorsitzende');
    } else {
        $runOff = invariantOpen($runOff);
        $standing = $runOff->candidates()->where('status', ElectionCandidateStatus::Approved)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        castElectionBallot($runOff, $voters[0], array_fill_keys($standing, 'for'));
        $runOff = app(CloseRound::class)->handle($runOff);
        expect($standing)->toHaveCount(2)
            ->and(app(TallyRound::class)->effective($runOff)->elected)->toBe($standing);
        $kasse = invariantOpen($kasse);
    }

    if ($window !== []) {
        test()->travel(90)->minutes();
    }

    foreach ($kasseVoters as $voter) {
        castElectionBallot($kasse->fresh(), $voter, [invariantCandidate($kasse, $winner)->id => 'for']);
    }

    if ($position === 'after') {
        app(ElectionStewardship::class)->annul($runOff->fresh(), $board, 'zurück an die Vorsitzende');
    }

    if ($window !== []) {
        test()->travel(2)->hours();
    }

    [$kasse, $vorsitz] = [$kasse->fresh(), $vorsitz->fresh()];
    expect($position === 'before' ? $kasse->position < $vorsitz->position : $kasse->position > $vorsitz->position)->toBeTrue()
        ->and($kasse->parent_round_id)->toBeNull()
        ->and($kasse->seats)->toBe(1)
        ->and($kasse->mode)->toBe($window !== [] ? ElectionRoundMode::Window : ElectionRoundMode::Live)
        ->and($window !== [] ? $kasse->closes_at->isPast() : $kasse->closes_at->isFuture())->toBeTrue()
        ->and($kasse->opened_at)->not->toBeNull()
        ->and($kasse->closed_at)->toBeNull()
        ->and($kasse->annulled_at)->toBeNull()
        ->and(invariantCandidate($kasse, $a)->status)->toBe($status)
        ->and($runOff->fresh()->annulled_at)->not->toBeNull()
        ->and(ElectionRound::query()->where('election_event_id', $evening['election']->id)->whereNotNull('opened_at')->whereNull('closed_at')->whereNull('annulled_at')->pluck('title')->all())->toBe(['Kasse'])
        ->and(app(TallyRound::class)->effective($vorsitz)->tied)->toHaveCount(3);

    return [$evening, $kasse];
}

it('K first-level, opened and not sealed, before or after the tie, live or a window over by the clock, with A approved, pending, rejected or withdrawn there: only an approved A holds back the casting vote for A, and nobody is elected twice', function (string $position, string $mode, ElectionCandidateStatus $status) {
    [$evening, $kasse] = invariantOpenedKasse($position, $mode, $status);
    ['board' => $board, 'election' => $election, 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $b, 'd' => $d] = $evening;

    if ($status === ElectionCandidateStatus::Approved) {
        expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', invariantOpenedRefusal('Kasse')])
            ->and(ElectionCastingVote::query()->where('election_round_id', $vorsitz->id)->exists())->toBeFalse();

        app(CloseRound::class)->handle($kasse);
        $free = array_values(array_diff(app(TallyRound::class)->effective($vorsitz)->tied, [(int) invariantCandidate($vorsitz, $a)->id]));

        expect(invariantElected($election)[$a->id])->toBe(['Kasse'])
            ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Kasse', invariantElectedRefusal('Kasse')])
            ->and(invariantOutcome(fn () => app(RecordCastingVote::class)->handle($vorsitz->fresh(), $board, $free, 'Erika')))->toBe('ok')
            ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz']);
    } else {
        $chosenIds = array_map(fn (EinundzwanzigPleb $member): int => (int) invariantCandidate($vorsitz, $member)->id, $evening['chosen']);
        sort($chosenIds);

        expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok')
            ->and(ElectionCastingVote::query()->where('election_round_id', $vorsitz->id)->value('candidate_ids'))->toBe($chosenIds);

        app(CloseRound::class)->handle($kasse);

        expect(invariantElected($election)[$a->id])->toBe(['Vorsitz'])
            ->and(invariantElected($election)[$d->id])->toBe(['Kasse']);
    }

    expectNobodyElectedTwice($election);
})->with(['before the tie' => 'before', 'after the tie' => 'after'])
    ->with(['live' => 'live', 'window over by the clock' => 'window'])
    ->with(['approved' => ElectionCandidateStatus::Approved, 'pending' => ElectionCandidateStatus::Pending, 'rejected' => ElectionCandidateStatus::Rejected, 'withdrawn' => ElectionCandidateStatus::Withdrawn]);

it('a run-off of K placed before the tie cannot hold a chosen member of the tie: with A level in the tie of K, OpenRound refuses to open the later Vorsitz with A on its ballot', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $kasse = draftElectionRound(['position' => 1, 'title' => 'Kasse', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 1], candidates: 0, election: $election);
    $vorsitz = draftElectionRound(['position' => 2, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 0, election: $election);
    [$a, $d] = [electionVoter(), electionVoter()];
    invariantStand($kasse, $a, $board);
    invariantStand($kasse, $d, $board);
    $kasseVoters = [electionVoter(), electionVoter()];
    $kasse = invariantOpen($kasse);
    castElectionBallot($kasse, $kasseVoters[0], [invariantCandidate($kasse, $a)->id => 'for']);
    castElectionBallot($kasse, $kasseVoters[1], [invariantCandidate($kasse, $d)->id => 'for']);
    $kasse = app(CloseRound::class)->handle($kasse);
    // Created while „Vorsitz“ is a draft, so the run-off of K takes the place right after K.
    $runOffK = app(CreateRunOff::class)->handle($kasse, $board, resolvedByAssembly: true);
    foreach ([$a, electionVoter(), electionVoter()] as $member) {
        invariantStand($vorsitz, $member, $board);
    }

    expect($runOffK->fresh()->position)->toBeLessThan($vorsitz->fresh()->position)
        ->and(invariantCandidate($runOffK, $a)->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(invariantOutcome(fn () => app(OpenRound::class)->handle($vorsitz->fresh())))->toBe(['blocked', $a->id, 'Kasse', 'A candidate of this round is level in an undecided tie of an earlier round of the election.'])
        ->and($vorsitz->fresh()->opened_at)->toBeNull();
});

it('a run-off of K placed after the tie, opened and not sealed, with A approved or withdrawn there: only an approved A holds back the casting vote for A, and nobody is elected twice', function (ElectionCandidateStatus $status) {
    $evening = invariantEvening('lowest');
    ['board' => $board, 'election' => $election, 'kasse' => $kasse, 'vorsitz' => $vorsitz, 'a' => $a, 'b' => $b, 'd' => $d] = $evening;

    $runOffV = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    $voters = [electionVoter(), electionVoter()];
    $kasse = invariantOpen($kasse);
    castElectionBallot($kasse, $voters[0], [invariantCandidate($kasse, $a)->id => 'for']);
    castElectionBallot($kasse, $voters[1], [invariantCandidate($kasse, $d)->id => 'for']);
    $kasse = app(CloseRound::class)->handle($kasse);
    $runOffK = app(CreateRunOff::class)->handle($kasse, $board, resolvedByAssembly: true);

    if ($status === ElectionCandidateStatus::Withdrawn) {
        app(Candidacies::class)->withdraw(invariantCandidate($runOffK, $a), $board, 'tritt im Vorsitz an');
    }

    $runOffK = invariantOpen($runOffK);
    app(Candidacies::class)->withdraw(invariantCandidate($runOffV, $a), $board, 'steht in der Stichwahl Kasse');
    app(ElectionStewardship::class)->annul(invariantOpen($runOffV), $board, 'zurück an die Vorsitzende');

    [$runOffK, $vorsitz] = [$runOffK->fresh(), $vorsitz->fresh()];
    expect($runOffK->title)->toBe('Stichwahl Kasse')
        ->and($runOffK->parent_round_id)->toBe($kasse->id)
        ->and($runOffK->position)->toBeGreaterThan($vorsitz->position)
        ->and($runOffK->mode)->toBe(ElectionRoundMode::Live)
        ->and($runOffK->closes_at->isFuture())->toBeTrue()
        ->and($runOffK->opened_at)->not->toBeNull()
        ->and($runOffK->closed_at)->toBeNull()
        ->and(invariantCandidate($runOffK, $a)->status)->toBe($status)
        ->and($runOffV->fresh()->annulled_at)->not->toBeNull();

    $winner = $status === ElectionCandidateStatus::Approved ? $a : $d;
    castElectionBallot($runOffK, $voters[0], [invariantCandidate($runOffK, $winner)->id => 'for']);

    if ($status === ElectionCandidateStatus::Approved) {
        expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Stichwahl Kasse', invariantOpenedRefusal('Stichwahl Kasse')]);
        app(CloseRound::class)->handle($runOffK);
        $free = array_values(array_diff(app(TallyRound::class)->effective($vorsitz)->tied, [(int) invariantCandidate($vorsitz, $a)->id]));

        expect(invariantElected($election)[$a->id])->toBe(['Stichwahl Kasse'])
            ->and(invariantCast($vorsitz, $board, $evening['chosen']))->toBe(['blocked', $a->id, 'Stichwahl Kasse', invariantElectedRefusal('Stichwahl Kasse')])
            ->and(invariantOutcome(fn () => app(RecordCastingVote::class)->handle($vorsitz->fresh(), $board, $free, 'Erika')))->toBe('ok')
            ->and(invariantElected($election)[$b->id])->toBe(['Vorsitz']);
    } else {
        expect(invariantCast($vorsitz, $board, $evening['chosen']))->toBe('ok');
        app(CloseRound::class)->handle($runOffK);

        expect(invariantElected($election)[$a->id])->toBe(['Vorsitz'])
            ->and(invariantElected($election)[$d->id])->toBe(['Stichwahl Kasse']);
    }

    expectNobodyElectedTwice($election);
})->with(['approved' => ElectionCandidateStatus::Approved, 'withdrawn' => ElectionCandidateStatus::Withdrawn]);
