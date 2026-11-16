<?php

/*
 * Double election through a tie, and the deadlocks around it: each layer's own
 * refusal pinned (the open-time tie guard in OpenRound, the elected-elsewhere
 * check in RecordCastingVote), so neither hides behind the other. Written as
 * probes by the round-2 gate of package j2g; each one kills a mutant the
 * earlier tests let through (a2, a3, a4, b2, b3, b8).
 */
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
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
use App\Services\Election\RecordCastingVote;
use App\Services\Election\TallyRound;

require_once __DIR__.'/helpers.php';

function tieGuardOutcome(Closure $act): string
{
    try {
        $act();
    } catch (ElectionCandidateBlockedException $e) {
        return 'blocked: '.$e->getMessage();
    } catch (ElectionActionRefusedException|ElectionRoundStateException $e) {
        return 'refused: '.$e->getMessage();
    }

    return 'ok';
}

function tieGuardStand(ElectionRound $round, int $pleb): int
{
    return (int) ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $pleb, 'status' => ElectionCandidateStatus::Approved])->id;
}

function tieGuardCand(ElectionRound $round, int $pleb): int
{
    return (int) $round->candidates()->where('einundzwanzig_pleb_id', $pleb)->value('id');
}

/** open, each voter names the given members (list per voter), seal */
function tieGuardRun(ElectionRound $round, array $ballots, ?array $voters = null): ElectionRound
{
    $voters ??= array_map(fn () => electionVoter(), $ballots);
    $round = app(OpenRound::class)->handle($round->fresh());
    foreach ($ballots as $i => $plebs) {
        $sel = [];
        foreach ($plebs as $p) {
            $sel[tieGuardCand($round, $p)] = 'for';
        }
        castElectionBallot($round, $voters[$i], $sel);
    }

    return app(CloseRound::class)->handle($round);
}

/** titles of sealed, non-annulled rounds of the election whose effective result elects member $pleb, chain-annulled excluded */
function tieGuardElectedIn(Election $election, int $pleb): array
{
    $out = [];
    foreach (ElectionRound::query()->where('election_event_id', $election->id)->get() as $r) {
        if (! $r->isSealed()) {
            continue;
        }
        $c = tieGuardCand($r, $pleb);
        if ($c && in_array($c, app(TallyRound::class)->effective($r)->elected, true)) {
            $out[] = $r->title;
        }
    }

    return $out;
}

function tieGuardCast(ElectionRound $round, EinundzwanzigPleb $board, array $plebs): string
{
    return tieGuardOutcome(fn () => app(RecordCastingVote::class)->handle($round->fresh(), $board, array_map(fn ($p) => tieGuardCand($round, $p), $plebs), 'Erika'));
}

/** election with Kasse (pos 1, no candidates yet) and Vorsitz (pos 2) sealed tied; seats 1 => A:B, seats 2 => A,B,C all level for both seats */
function tieGuardSetup(int $seats = 1): array
{
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $kasse = draftElectionRound(['position' => 1, 'title' => 'Kasse', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $election);
    $vorsitz = draftElectionRound(['position' => 2, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => $seats], candidates: $seats === 1 ? 2 : 3, election: $election);
    $m = $vorsitz->candidates()->orderBy('id')->pluck('einundzwanzig_pleb_id')->map(fn ($i) => (int) $i)->all();
    $ballots = $seats === 1 ? [[$m[0]], [$m[1]]] : [[$m[0], $m[1]], [$m[1], $m[2]], [$m[2], $m[0]]];
    $GLOBALS['tieGuardVoters'] = array_map(fn () => electionVoter(), $ballots);
    $vorsitz = tieGuardRun($vorsitz, $ballots, $GLOBALS['tieGuardVoters']);
    $res = app(TallyRound::class)->effective($vorsitz);
    expect($res->tiedSeats)->toBe($seats)->and(count($res->tied))->toBe($seats + 1);

    return [$board, $election, $kasse, $vorsitz, $m];
}

it('P1 run-off exemption: Kasse elects A while run-off is draft; run-off opened/closed after withdrawing A', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    tieGuardStand($kasse, $a);
    $kasse = tieGuardRun($kasse, [[$a], [$a]]);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($runOff->fresh())))->toContain('has been elected');
    app(Candidacies::class)->withdraw(ElectionCandidate::findOrFail(tieGuardCand($runOff, $a)), $board, 'x');
    $runOff = tieGuardRun($runOff, [[$b]], array_slice($GLOBALS['tieGuardVoters'], 0, 1));
    expect(tieGuardElectedIn($election, $a))->toBe(['Kasse'])
        ->and(tieGuardElectedIn($election, $b))->toBe([$runOff->title])
        ->and(tieGuardCast($vorsitz, $board, [$a]))->toContain('refused');
});

it('P1b claim 7: (b) reachable without DB write: withdraw A from draft run-off, open it, annul it, casting for A', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    tieGuardStand($kasse, $a);
    $kasse = tieGuardRun($kasse, [[$a], [$a]]);
    app(Candidacies::class)->withdraw(ElectionCandidate::findOrFail(tieGuardCand($runOff, $a)), $board, 'x');
    $runOff = app(OpenRound::class)->handle($runOff->fresh());
    app(ElectionStewardship::class)->annul($runOff, $board, 'falsch');
    $castA = tieGuardCast($vorsitz, $board, [$a]);
    dump('P1b castA: '.$castA);
    expect($castA)->toContain('blocked: The chair cannot decide for a candidate who is elected in the round "Kasse"')
        ->and(tieGuardCast($vorsitz, $board, [$b]))->toBe('ok')
        ->and(tieGuardElectedIn($election, $a))->toBe(['Kasse']);
});

it('P2 annulled run-off: tie blocks Kasse again', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    $runOff = app(OpenRound::class)->handle($runOff->fresh());
    tieGuardStand($kasse, $a);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toContain('also stands in the opened round');
    app(ElectionStewardship::class)->annul($runOff, $board, 'falsch');
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toContain('undecided tie of the round "Vorsitz"');
    // new run-off draft -> exemption again
    $runOff2 = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toBe('ok');
});

it('P2b run-off ties again: casting in run-off decides; Kasse blocked until then', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    $runOff = tieGuardRun($runOff, [[$a], [$b]], array_slice($GLOBALS['tieGuardVoters'], 0, 2));
    tieGuardStand($kasse, $a);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toContain('undecided tie of the round "'.$runOff->title.'"');
    expect(tieGuardCast($runOff, $board, [$a]))->toBe('ok');
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toContain('has been elected');
    expect(tieGuardElectedIn($election, $a))->toBe([$runOff->title]);
});

it('P3 repeat of the tied round', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    app(ElectionStewardship::class)->annul($vorsitz, $board, 'falsch');
    $repeat = app(ElectionStewardship::class)->repeat($vorsitz->fresh(), $board);
    expect(tieGuardCast($vorsitz, $board, [$a]))->toContain('refused');
    $repeat = tieGuardRun($repeat, [[$a], [$b]]);
    tieGuardStand($kasse, $a);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toContain('undecided tie of the round "'.$repeat->title.'"');
    expect(tieGuardCast($repeat, $board, [$a]))->toBe('ok');
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toContain('has been elected');
    expect(tieGuardElectedIn($election, $a))->toBe([$repeat->title]);
});

it('P3b repeat as draft does not block Kasse (H1), and then repeat refuses A', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    app(ElectionStewardship::class)->annul($vorsitz, $board, 'falsch');
    $repeat = app(ElectionStewardship::class)->repeat($vorsitz->fresh(), $board);
    tieGuardStand($kasse, $a);
    $kasse = tieGuardRun($kasse, [[$a]]);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($repeat->fresh())))->toContain('has been elected')
        ->and(tieGuardElectedIn($election, $a))->toBe(['Kasse']);
});

it('P4 multi-seat tie (2 of A,B,C) with chosen elected elsewhere via reachable path', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b, $c]] = tieGuardSetup(2);
    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    tieGuardStand($kasse, $a);
    $kasse = tieGuardRun($kasse, [[$a]]);
    app(Candidacies::class)->withdraw(ElectionCandidate::findOrFail(tieGuardCand($runOff, $a)), $board, 'x');
    $runOff = app(OpenRound::class)->handle($runOff->fresh());
    app(ElectionStewardship::class)->annul($runOff, $board, 'falsch');
    expect(tieGuardCast($vorsitz, $board, [$a, $b]))->toContain('blocked')
        ->and(tieGuardCast($vorsitz, $board, [$c, $a]))->toContain('blocked')
        ->and(tieGuardCast($vorsitz, $board, [$b, $c]))->toBe('ok')
        ->and(tieGuardElectedIn($election, $a))->toBe(['Kasse'])
        ->and(app(TallyRound::class)->effective($vorsitz->fresh())->elected)->toHaveCount(2);
});

it('P4b deadlock try: 2 of A,B,C with A and B elected elsewhere; exit by a new run-off without DB write', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b, $c]] = tieGuardSetup(2);
    $sekretariat = draftElectionRound(['position' => 0, 'title' => 'Sekretariat', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $election);
    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    tieGuardStand($kasse, $a);
    tieGuardStand($sekretariat, $b);
    $kasse = tieGuardRun($kasse, [[$a]]);
    $sekretariat = tieGuardRun($sekretariat, [[$b]]);
    foreach ([$a, $b] as $p) {
        app(Candidacies::class)->withdraw(ElectionCandidate::findOrFail(tieGuardCand($runOff, $p)), $board, 'x');
    }
    $runOff = app(OpenRound::class)->handle($runOff->fresh());
    app(ElectionStewardship::class)->annul($runOff, $board, 'falsch');
    $r = [tieGuardCast($vorsitz, $board, [$a, $c]), tieGuardCast($vorsitz, $board, [$b, $c]), tieGuardCast($vorsitz, $board, [$c]), tieGuardCast($vorsitz, $board, [$a, $b])];
    dump($r);
    expect($r[0])->toContain('blocked')->and($r[1])->toContain('blocked')->and($r[2])->toContain('refused')->and($r[3])->toContain('blocked');
    $runOff2 = app(CreateRunOff::class)->handle($vorsitz->fresh(), $board, resolvedByAssembly: true);
    foreach ([$a, $b] as $p) {
        app(Candidacies::class)->withdraw(ElectionCandidate::findOrFail(tieGuardCand($runOff2, $p)), $board, 'x');
    }
    $runOff2 = tieGuardRun($runOff2, [[$c]], array_slice($GLOBALS['tieGuardVoters'], 0, 1));
    foreach ([$a, $b, $c] as $p) {
        expect(count(tieGuardElectedIn($election, $p)))->toBe(1);
    }
});

it('P5 casting vote recorded before the other contest elects the same person', function (int $seats) {
    [$board, $election, $kasse, $vorsitz, $m] = tieGuardSetup($seats);
    $chosen = $seats === 1 ? [$m[0]] : [$m[0], $m[1]];
    expect(tieGuardCast($vorsitz, $board, $chosen))->toBe('ok');
    tieGuardStand($kasse, $m[0]);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toContain('has been elected');
    $free = $seats === 1 ? $m[1] : $m[2];
    $k2 = draftElectionRound(['position' => 4, 'title' => 'Beisitz', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $election);
    tieGuardStand($k2, $free);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($k2->fresh())))->toBe('ok');
})->with([1, 2]);

it('P6 two elections side by side: no leak, and no double within one', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    $other = Election::factory()->create();
    $otherKasse = draftElectionRound(['position' => 1, 'title' => 'Kasse E2', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $other);
    tieGuardStand($otherKasse, $a);
    $ov = [electionVoter(), electionVoter()];
    $o = tieGuardOutcome(fn () => app(OpenRound::class)->handle($otherKasse->fresh()));
    dump('P6 other election open: '.$o);
    $otherKasse = $otherKasse->fresh();
    if ($otherKasse->opened_at !== null) {
        foreach ($ov as $v) {
            castElectionBallot($otherKasse, $v, [tieGuardCand($otherKasse, $a) => 'for']);
        }
        app(CloseRound::class)->handle($otherKasse);
    }
    $castA = tieGuardCast($vorsitz, $board, [$a]);
    dump('P6 casting in E1 for A elected in E2: '.$castA);
    expect($castA)->toBe('ok');
    tieGuardStand($kasse, $a);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toContain('has been elected')
        ->and(tieGuardElectedIn($election, $a))->toBe(['Vorsitz']);
});

it('P7 Kasse tie with draft run-off elects A in its run-off while Vorsitz tie waits; nobody twice', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    $runOffV = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    $c = (int) electionVoter()->id;
    tieGuardStand($kasse, $a);
    tieGuardStand($kasse, $c);
    $kv = [electionVoter(), electionVoter()];
    $kasse = tieGuardRun($kasse, [[$a], [$c]], $kv);
    $runOffK = app(CreateRunOff::class)->handle($kasse->fresh(), $board, resolvedByAssembly: true);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($runOffV->fresh())))->toContain('undecided tie of an earlier round');
    $runOffK = tieGuardRun($runOffK, [[$a]], [$kv[0]]);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($runOffV->fresh())))->toContain('has been elected');
    app(Candidacies::class)->withdraw(ElectionCandidate::findOrFail(tieGuardCand($runOffV, $a)), $board, 'x');
    $runOffV = app(OpenRound::class)->handle($runOffV->fresh());
    app(ElectionStewardship::class)->annul($runOffV, $board, 'falsch');
    expect(tieGuardCast($vorsitz, $board, [$a]))->toContain('blocked')
        ->and(tieGuardCast($vorsitz, $board, [$b]))->toBe('ok')
        ->and(tieGuardElectedIn($election, $a))->toBe([$runOffK->title]);
});

it('P8 a non-tied loser of the later tie round stands in Kasse: opens', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $kasse = draftElectionRound(['position' => 1, 'title' => 'Kasse', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 0, election: $election);
    $vorsitz = draftElectionRound(['position' => 2, 'title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes], candidates: 3, election: $election);
    [$a, $b, $d] = $vorsitz->candidates()->orderBy('id')->pluck('einundzwanzig_pleb_id')->map(fn ($i) => (int) $i)->all();
    $vorsitz = tieGuardRun($vorsitz, [[$a], [$b]]);
    expect(app(TallyRound::class)->effective($vorsitz)->tied)->toHaveCount(2);
    tieGuardStand($kasse, $d);
    expect(tieGuardOutcome(fn () => app(OpenRound::class)->handle($kasse->fresh())))->toBe('ok');
});

it('P9 B stood in Kasse and lost (A elected there); casting for B in Vorsitz is allowed', function () {
    [$board, $election, $kasse, $vorsitz, [$a, $b]] = tieGuardSetup();
    $runOff = app(CreateRunOff::class)->handle($vorsitz, $board, resolvedByAssembly: true);
    tieGuardStand($kasse, $a);
    tieGuardStand($kasse, $b);
    $kasse = tieGuardRun($kasse, [[$a], [$a]]);
    app(Candidacies::class)->withdraw(ElectionCandidate::findOrFail(tieGuardCand($runOff, $a)), $board, 'x');
    $runOff = app(OpenRound::class)->handle($runOff->fresh());
    app(ElectionStewardship::class)->annul($runOff, $board, 'falsch');
    expect(tieGuardCast($vorsitz, $board, [$b]))->toBe('ok')
        ->and(tieGuardElectedIn($election, $b))->toBe(['Vorsitz'])
        ->and(tieGuardElectedIn($election, $a))->toBe(['Kasse']);
});
