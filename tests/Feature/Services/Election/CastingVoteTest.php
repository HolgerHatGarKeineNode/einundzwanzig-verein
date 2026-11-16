<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionOutcome;
use App\Enums\ElectionTieKind;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionCastingVote;
use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\OpenRound;
use App\Services\Election\RecordCastingVote;
use App\Services\Election\TallyRound;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
 * The chair's casting vote on a tie (statutes Art. 6: „Bei Stimmengleichheit
 * fällt die/der Vorsitzende den Stichentscheid.“). The Wahlleitung records
 * it; the effective result is the count plus that decision, and everything
 * that says who is elected reads it. A run-off of a tie exists only by
 * resolution of the assembly, and the two exclude each other.
 *
 * Mutations that must turn this file red: accept a casting vote with the
 * wrong number of candidates; let OpenRound read the raw count (a decided tie
 * still blocks); let CreateRunOff set up a tie run-off without the
 * resolution.
 */

/**
 * A sealed round of `$rule`: one voter per entry of `$ballots`, each entry
 * mapping a candidate index (by id order) to a choice; `[]` is a blank vote.
 *
 * @param  list<array<int, string>>  $ballots
 * @param  array<string, mixed>  $attributes
 * @return array{0: ElectionRound, 1: list<int>}
 */
function tiedRound(ElectionCountingRule $rule, int $seats, int $candidates, array $ballots, ?Election $election = null, array $attributes = []): array
{
    $voters = array_map(fn (): EinundzwanzigPleb => electionVoter(), $ballots);
    $round = app(OpenRound::class)->handle(draftElectionRound(['counting_rule' => $rule, 'seats' => $seats, ...$attributes], $candidates, $election));
    $ids = $round->candidates()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

    foreach ($ballots as $index => $ballot) {
        $selection = [];

        foreach ($ballot as $candidate => $choice) {
            $selection[$ids[$candidate]] = $choice;
        }

        castElectionBallot($round, $voters[$index], $selection);
    }

    return [app(CloseRound::class)->handle($round), $ids];
}

/**
 * 1:1 between two candidates of a single-majority round.
 *
 * @return array{0: ElectionRound, 1: list<int>}
 */
function evenSplitRound(?Election $election = null, array $attributes = []): array
{
    return tiedRound(ElectionCountingRule::SingleMajority, 1, 2, [[0 => 'for'], [1 => 'for']], $election, $attributes);
}

function castingVote(ElectionRound $round, EinundzwanzigPleb $actor, array $candidates, string $chair = 'Erika Vorsitz', ?string $note = null): ElectionCastingVote
{
    return app(RecordCastingVote::class)->handle($round, $actor, $candidates, $chair, $note);
}

/**
 * The message of the refusal `$act` ends with.
 *
 * Any throwable, not only the expected refusals: an unexpected one (a unique
 * index answering for a missing check, K7) then fails the comparison with the
 * expected message as an assertion instead of erroring out.
 */
function castingRefusal(Closure $act): string
{
    try {
        $act();
    } catch (Throwable $exception) {
        return $exception::class.': '.$exception->getMessage();
    }

    return 'recorded';
}

/**
 * Whether `$open` opens a round, or which refusal of the chain rule it ends with.
 */
function castingChainOutcome(Closure $open): string
{
    try {
        $open();
    } catch (ElectionRoundStateException $exception) {
        return match (true) {
            str_contains($exception->getMessage(), 'has been elected') => 'elected',
            str_contains($exception->getMessage(), 'undecided tie') => 'tie',
            default => throw $exception,
        };
    }

    return 'opens';
}

/**
 * Member `$plebId` stands, approved, in `$round`.
 */
function castingChainStand(ElectionRound $round, int $plebId): void
{
    ElectionCandidate::factory()->create([
        'election_round_id' => $round->getKey(),
        'einundzwanzig_pleb_id' => $plebId,
        'status' => ElectionCandidateStatus::Approved,
    ]);
}

/**
 * @return list<int>
 */
function castingChainMembers(ElectionRound $round): array
{
    return $round->candidates()->orderBy('id')->pluck('einundzwanzig_pleb_id')->map(fn (mixed $id): int => (int) $id)->all();
}

/**
 * Open `$round`, have each voter name one member, and seal it.
 *
 * @param  list<EinundzwanzigPleb>  $voters
 * @param  list<int>  $plebIds
 */
function castingChainVoteAndSeal(ElectionRound $round, array $voters, array $plebIds): ElectionRound
{
    $round = app(OpenRound::class)->handle($round->fresh());

    foreach ($plebIds as $index => $plebId) {
        castElectionBallot($round, $voters[$index], [(int) $round->candidates()->where('einundzwanzig_pleb_id', $plebId)->value('id') => 'for']);
    }

    return app(CloseRound::class)->handle($round);
}

it('records the casting vote on a tie between candidates and elects whom the chair decided for', function () {
    [$board] = electionBoard(1);
    [$round, [$a, $b]] = evenSplitRound();

    castingVote($round, $board, [$b], 'Erika Vorsitz', 'Entschieden nach Vorstellung');

    $raw = app(TallyRound::class)->handle($round);
    $effective = app(TallyRound::class)->effective($round);

    expect($raw->outcome)->toBe(ElectionOutcome::Tie)
        ->and($raw->tied)->toBe([$a, $b])
        ->and($effective->outcome)->toBe(ElectionOutcome::Complete)
        ->and($effective->elected)->toBe([$b])
        ->and($effective->tied)->toBe([])
        ->and($effective->tieKind)->toBe(ElectionTieKind::Candidates)
        ->and($effective->castingVote->chair)->toBe('Erika Vorsitz')
        // The raw counts are never altered.
        ->and($effective->counts)->toBe($raw->counts);

    $line = ElectionAuditEntry::query()->where('action', 'casting_vote_recorded')->sole();

    expect($line->election_round_id)->toBe($round->id)
        ->and($line->actor_pleb_id)->toBe($board->id)
        ->and($line->details)->toBe(['chair' => 'Erika Vorsitz', 'decided_for' => (string) $b, 'reason' => 'Entschieden nach Vorstellung']);
});

it('lets the chair decide yes or no on a yes = no tie, none included', function (array $chosen, ElectionOutcome $outcome) {
    [$board] = electionBoard(1);
    [$round, [$a]] = tiedRound(ElectionCountingRule::YesNoPerCandidate, 1, 1, [[0 => 'yes'], [0 => 'no']]);

    castingVote($round, $board, $chosen === [] ? [] : [$a]);
    $effective = app(TallyRound::class)->effective($round);

    expect($effective->outcome)->toBe($outcome)
        ->and($effective->elected)->toBe($chosen === [] ? [] : [$a])
        ->and($effective->tieKind)->toBe(ElectionTieKind::YesEqualsNo);
})->with([
    'ja' => [['a'], ElectionOutcome::Complete],
    'nein' => [[], ElectionOutcome::Understaffed],
]);

it('refuses a casting vote that does not fit or comes at the wrong time', function (string $case, string $expected) {
    [$board] = electionBoard(1);
    $member = electionVoter();

    $act = match ($case) {
        'wrong count' => function () use ($board) {
            [$round, [$a, $b]] = evenSplitRound();

            return fn () => castingVote($round, $board, [$a, $b]);
        },
        'none for a tie between candidates' => function () use ($board) {
            [$round] = evenSplitRound();

            return fn () => castingVote($round, $board, []);
        },
        'too many on yes = no' => function () use ($board) {
            [$round, $ids] = tiedRound(ElectionCountingRule::YesNoPerCandidate, 1, 2, [[0 => 'yes', 1 => 'yes'], [0 => 'no', 1 => 'no']]);

            return fn () => castingVote($round, $board, $ids);
        },
        'not tied' => function () use ($board) {
            [$round, [$a]] = tiedRound(ElectionCountingRule::SingleMajority, 1, 2, [[0 => 'for'], [0 => 'for'], [1 => 'for']]);

            return fn () => castingVote($round, $board, [$a]);
        },
        'no Stimmengleichheit (4:4:2)' => function () use ($board) {
            [$round, [$a]] = tiedRound(ElectionCountingRule::SingleMajority, 1, 3, [[0 => 'for'], [0 => 'for'], [1 => 'for'], [1 => 'for'], [2 => 'for']]);

            return fn () => castingVote($round, $board, [$a]);
        },
        'annulled' => function () use ($board) {
            [$round, [$a]] = evenSplitRound();
            app(ElectionStewardship::class)->annul($round, $board, 'Falsche Liste');

            return fn () => castingVote($round, $board, [$a]);
        },
        'not sealed' => function () use ($board) {
            $round = openElectionRound();

            return fn () => castingVote($round, $board, [(int) $round->candidates()->value('id')]);
        },
        'already decided' => function () use ($board) {
            [$round, [$a, $b]] = evenSplitRound();
            castingVote($round, $board, [$a]);

            return fn () => castingVote($round, $board, [$b]);
        },
        'run-off exists' => function () use ($board) {
            [$round, [$a]] = evenSplitRound();
            app(CreateRunOff::class)->handle($round, $board, resolvedByAssembly: true);

            return fn () => castingVote($round, $board, [$a]);
        },
        'foreign candidate' => function () use ($board) {
            [$round] = evenSplitRound();
            [, [$foreign]] = evenSplitRound();

            return fn () => castingVote($round, $board, [$foreign]);
        },
        'not level (3-way, the third has no votes)' => function () use ($board) {
            [$round, [, , $c]] = tiedRound(ElectionCountingRule::SingleMajority, 1, 3, [[0 => 'for'], [1 => 'for']]);

            return fn () => castingVote($round, $board, [$c]);
        },
        'no chair' => function () use ($board) {
            [$round, [$a]] = evenSplitRound();

            return fn () => castingVote($round, $board, [$a], '   ');
        },
        'chair over 60 characters' => function () use ($board) {
            [$round, [$a]] = evenSplitRound();

            return fn () => castingVote($round, $board, [$a], str_repeat('ä', 61));
        },
        'unauthorised' => function () use ($member) {
            [$round, [$a]] = evenSplitRound();

            return fn () => castingVote($round, $member, [$a]);
        },
    };

    $act = $act();
    $lines = ElectionAuditEntry::query()->count();
    $rows = ElectionCastingVote::query()->count();

    expect(castingRefusal($act))->toContain($expected)
        ->and(ElectionAuditEntry::query()->count())->toBe($lines)
        ->and(ElectionCastingVote::query()->count())->toBe($rows);
})->with([
    ['wrong count', 'wrong number of candidates'],
    ['none for a tie between candidates', 'wrong number of candidates'],
    ['too many on yes = no', 'wrong number of candidates'],
    ['not tied', 'not a tie'],
    ['no Stimmengleichheit (4:4:2)', 'not a tie'],
    ['annulled', 'annulled round takes no casting vote'],
    ['not sealed', ElectionRoundStateException::class],
    ['already decided', 'decided already'],
    ['run-off exists', 'has a run-off'],
    ['foreign candidate', 'level in the tie'],
    ['not level (3-way, the third has no votes)', 'level in the tie'],
    ['no chair', 'must name the chair'],
    ['chair over 60 characters', 'in at most 60 characters'],
    ['unauthorised', AuthorizationException::class],
]);

it('records a chair name of exactly 60 characters, trimmed, for the share view', function () {
    [$board] = electionBoard(1);
    [$round, [$a]] = evenSplitRound();
    $chair = 'Erika Magdalena Mustermann-Vorsitz von der Leyen-Schwarzwald';

    expect(mb_strlen($chair))->toBe(RecordCastingVote::MAX_CHAIR_LENGTH)
        ->and(castingVote($round, $board, [$a], "  {$chair}  ")->chair_name)->toBe($chair);
});

it('keeps a recorded casting vote as it is, and annulling the round voids it', function () {
    [$board] = electionBoard(1);
    [$round, [$a]] = evenSplitRound();
    $castingVote = castingVote($round, $board, [$a]);

    expect(fn () => $castingVote->forceFill(['chair_name' => 'Jemand anderes'])->save())->toThrow(ElectionRoundStateException::class)
        ->and(fn () => $castingVote->delete())->toThrow(ElectionRoundStateException::class);

    app(ElectionStewardship::class)->annul($round, $board, 'Falsche Liste');

    expect(fn () => app(TallyRound::class)->effective($round))->toThrow(ElectionRoundStateException::class)
        ->and(ElectionCastingVote::query()->where('election_round_id', $round->id)->exists())->toBeTrue();
});

it('does not apply a stored casting vote that no longer fits the count', function () {
    [$board] = electionBoard(1);
    [$round, [$a, $b]] = evenSplitRound();
    castingVote($round, $board, [$a]);

    // A hand-edited row naming both: elects nobody, the round stays tied.
    DB::table('election_casting_votes')->where('election_round_id', $round->id)->update(['candidate_ids' => json_encode([$a, $b])]);

    expect(app(TallyRound::class)->effective($round)->outcome)->toBe(ElectionOutcome::Tie)
        ->and(app(TallyRound::class)->effective($round)->elected)->toBe([]);
});

it('sets up the run-off of a tie only on the resolution of the assembly', function () {
    [$board] = electionBoard(1);
    [$round] = evenSplitRound();

    expect(castingRefusal(fn () => app(CreateRunOff::class)->handle($round, $board)))->toContain('needs the resolution of the assembly')
        ->and(ElectionRound::query()->where('parent_round_id', $round->id)->exists())->toBeFalse();

    $runOff = app(CreateRunOff::class)->handle($round, $board, resolvedByAssembly: true);

    expect($runOff->counting_rule)->toBe(ElectionCountingRule::MostVotes)
        ->and(ElectionAuditEntry::query()->where('action', 'run_off_created')->sole()->details)->toBe(['resolution' => 'assembly']);
});

it('sets up the run-off of a missed majority without a resolution, as before', function () {
    [$board] = electionBoard(1);
    // 2:1:1 — nobody above half, and no Stimmengleichheit.
    [$round] = tiedRound(ElectionCountingRule::SingleMajority, 1, 3, [[0 => 'for'], [0 => 'for'], [1 => 'for'], [2 => 'for']]);

    $runOff = app(CreateRunOff::class)->handle($round, $board);

    expect($runOff->parent_round_id)->toBe($round->id)
        ->and(ElectionAuditEntry::query()->where('action', 'run_off_created')->sole()->details)->toBeNull();
});

it('votes a yes = no tie again by yes and no when the assembly resolves a run-off', function () {
    [$board] = electionBoard(1);
    [$round] = tiedRound(ElectionCountingRule::YesNoPerCandidate, 1, 1, [[0 => 'yes'], [0 => 'no']]);

    expect(app(CreateRunOff::class)->handle($round, $board, resolvedByAssembly: true)->counting_rule)->toBe(ElectionCountingRule::YesNoPerCandidate);
});

it('sets up no run-off after a casting vote', function () {
    [$board] = electionBoard(1);
    [$round, [$a]] = evenSplitRound();
    castingVote($round, $board, [$a]);

    expect(castingRefusal(fn () => app(CreateRunOff::class)->handle($round, $board, resolvedByAssembly: true)))->toContain('decided by casting vote')
        ->and(app(CreateRunOff::class)->proposal($round)['candidates'])->toBe([]);
});

it('releases the candidates of a decided tie for later rounds: the elected stay blocked, the others stand', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    [$chair, [$a, $b]] = evenSplitRound($election, ['position' => 1, 'title' => 'Vorsitz']);
    $memberA = (int) DB::table('election_candidates')->where('id', $a)->value('einundzwanzig_pleb_id');
    $memberB = (int) DB::table('election_candidates')->where('id', $b)->value('einundzwanzig_pleb_id');

    $treasurer = draftElectionRound(['position' => 2, 'title' => 'Kasse'], candidates: 0, election: $election);
    castingChainStand($treasurer, $memberB);

    expect(castingChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('tie');

    castingVote($chair, $board, [$a]);

    expect(castingChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('opens');

    $assessor = draftElectionRound(['position' => 3, 'title' => 'Beisitz'], candidates: 0, election: $election);
    castingChainStand($assessor, $memberA);

    expect(castingChainOutcome(fn () => app(OpenRound::class)->handle($assessor->fresh())))->toBe('elected');
});

it('ends a run-off chain with the casting vote on a tie in the run-off', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $voters = [electionVoter(), electionVoter()];
    [$chair] = evenSplitRound($election, ['position' => 1, 'title' => 'Vorsitz']);
    $runOff = app(CreateRunOff::class)->handle($chair, $board, resolvedByAssembly: true);
    [$memberA, $memberB] = castingChainMembers($runOff);
    $runOff = castingChainVoteAndSeal($runOff, $voters, [$memberA, $memberB]);
    $runOffB = (int) $runOff->candidates()->where('einundzwanzig_pleb_id', $memberB)->value('id');

    castingVote($runOff, $board, [$runOffB]);

    $treasurer = draftElectionRound(['position' => 5, 'title' => 'Kasse'], candidates: 0, election: $election);
    castingChainStand($treasurer, $memberA);

    expect(app(TallyRound::class)->effective($runOff)->elected)->toBe([$runOffB])
        ->and(app(CreateRunOff::class)->proposal($runOff)['candidates'])->toBe([])
        ->and(castingChainOutcome(fn () => app(OpenRound::class)->handle($treasurer->fresh())))->toBe('opens');
});

it('verifies the casting vote against its audit line and prints the effective result', function () {
    [$board] = electionBoard(1);
    [$round, [$a, $b]] = evenSplitRound();
    castingVote($round, $board, [$b]);

    $status = Artisan::call('election:verify', ['round' => $round->id]);
    $output = Artisan::output();

    expect($status)->toBe(0)
        ->and($output)->toContain('Casting vote of the chair (Erika Vorsitz): elected ['.$b.'] of tied ['.$a.','.$b.'].');

    DB::table('election_casting_votes')->where('election_round_id', $round->id)->update(['candidate_ids' => json_encode([$a])]);

    $status = Artisan::call('election:verify', ['round' => $round->id]);

    expect($status)->toBe(1)
        ->and(Artisan::output())->toContain('MISMATCH: the recorded casting vote differs from its line in the audit log.');
});

/*
 * Row and audit line agree with each other but name both candidates of a
 * one-seat tie: only the fits() check of election:verify can see it.
 */
it('reports a casting vote that agrees with its audit line but does not fit the tie', function () {
    [$board] = electionBoard(1);
    [$round, [$a, $b]] = evenSplitRound();

    ElectionCastingVote::query()->create([
        'election_round_id' => $round->id,
        'candidate_ids' => [$a, $b],
        'chair_name' => 'Erika Vorsitz',
        'recorded_by_pleb_id' => $board->id,
    ]);
    app(ElectionAuditLog::class)->append($round->election, ElectionAuditAction::CastingVoteRecorded, $board, round: $round, details: [
        'chair' => 'Erika Vorsitz',
        'decided_for' => $a.','.$b,
    ]);

    $status = Artisan::call('election:verify', ['round' => $round->id]);
    $output = Artisan::output();

    expect($status)->toBe(1)
        ->and($output)->toContain('MISMATCH: the recorded casting vote does not fit the tie of the count.')
        ->not->toContain('differs from its line in the audit log')
        ->not->toContain('no longer holds its hash chain');
});

/*
 * Security jury I2: a stored casting vote whose candidates cannot be read was
 * applied as a decision for nobody, which on a yes = no tie is a valid
 * „nein“. Mutation that must turn this red: read an unreadable row as an
 * empty list again in `ElectionCastingVote::decision()`.
 */
it('does not apply an unreadable stored casting vote on a yes = no tie, and verify flags it', function (string $stored) {
    [$board] = electionBoard(1);
    [$round, [$a]] = tiedRound(ElectionCountingRule::YesNoPerCandidate, 1, 1, [[0 => 'yes'], [0 => 'no']]);
    castingVote($round, $board, []);

    expect(app(TallyRound::class)->effective($round)->outcome)->toBe(ElectionOutcome::Understaffed);

    DB::table('election_casting_votes')->where('election_round_id', $round->id)->update(['candidate_ids' => str_replace(':a', (string) $a, $stored)]);
    $effective = app(TallyRound::class)->effective($round);

    expect($effective->outcome)->toBe(ElectionOutcome::Tie)
        ->and($effective->castingVote)->toBeNull()
        ->and($effective->tied)->toBe([$a])
        ->and(Artisan::call('election:verify', ['round' => $round->id]))->toBe(1)
        ->and(Artisan::output())->toContain('MISMATCH: the recorded casting vote does not fit the tie of the count.');
})->with([
    'JSON null' => ['null'],
    'a string' => ['"Anna"'],
    'a number' => ['7'],
    'an object' => ['{"chosen": :a}'],
    'a nested list' => ['[[:a]]'],
]);

/*
 * Tests jury K2: `fits()` must only accept candidates who were level. The
 * escaped mutant was `||` → `&&` in the check of the chosen against the tied.
 */
it('does not apply a stored casting vote for a candidate who was not level', function (string $kind) {
    [$board] = electionBoard(1);

    [$round, $ids] = $kind === 'between candidates'
        // 1:1:0 — A and B are level for the seat, C is not.
        ? tiedRound(ElectionCountingRule::SingleMajority, 1, 3, [[0 => 'for'], [1 => 'for']])
        // A is level on yes = no, B is elected with two yes.
        : tiedRound(ElectionCountingRule::YesNoPerCandidate, 2, 2, [[0 => 'yes', 1 => 'yes'], [0 => 'no', 1 => 'yes']]);
    $notLevel = end($ids);
    $level = app(TallyRound::class)->handle($round)->tied;

    expect($level)->not->toContain($notLevel);

    ElectionCastingVote::query()->create([
        'election_round_id' => $round->id,
        'candidate_ids' => [$notLevel],
        'chair_name' => 'Erika Vorsitz',
        'recorded_by_pleb_id' => $board->id,
    ]);
    app(ElectionAuditLog::class)->append($round->election, ElectionAuditAction::CastingVoteRecorded, $board, round: $round, details: [
        'chair' => 'Erika Vorsitz',
        'decided_for' => (string) $notLevel,
    ]);
    $effective = app(TallyRound::class)->effective($round);

    expect($effective->outcome)->toBe(ElectionOutcome::Tie)
        ->and($effective->castingVote)->toBeNull()
        ->and($effective->tied)->toBe($level)
        ->and(Artisan::call('election:verify', ['round' => $round->id]))->toBe(1)
        ->and(Artisan::output())->toContain('MISMATCH: the recorded casting vote does not fit the tie of the count.')
        ->not->toContain('differs from its line in the audit log');
})->with(['between candidates', 'yes = no']);
