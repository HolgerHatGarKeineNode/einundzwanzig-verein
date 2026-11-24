<?php

use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionCandidateBlockedException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionCastingVote;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Models\Profile;
use App\Services\Election\Candidacies;
use App\Services\Election\CloseRound;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\OpenRound;
use App\Services\Election\RecordCastingVote;
use App\Services\Election\RefusalText;
use App\Services\Election\RoundManifest;
use App\Services\Election\RoundReport;
use App\Services\Election\TallyResult;
use App\Services\Election\TallyRound;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';
require_once __DIR__.'/helpers.php';

/*
 * A tie on every screen (statutes Art. 6: „Bei Stimmengleichheit fällt
 * die/der Vorsitzende den Stichentscheid.“): before the decision the tie and
 * the chair's pending casting vote, after it the result with a line naming
 * the chair, and a run-off only where the assembly resolved one. The board
 * enters the decision in a dialog that checks the number of candidates.
 *
 * Mutations that must turn this red: say „Darüber entscheidet eine
 * Stichwahl“ again for a tie; leave out the casting-vote line on a screen;
 * drop the result of a round annulled after its count from the minutes.
 */

/**
 * A board of one, logged in, in an announced election with one sealed
 * single-majority round where Alice Ambrosius and Bob Brenner got one vote
 * each — or, not tied, Alice Ambrosius both votes.
 *
 * @return array{0: EinundzwanzigPleb, 1: Election, 2: ElectionRound, 3: array{alice: int, bob: int}}
 */
function tiedEvening(string $title = 'Präsidium', bool $tied = true): array
{
    [$board] = electionBoard(1);
    $voters = [electionVoter(), electionVoter()];
    $election = Election::factory()->announced()->create(['name' => 'Vorstandswahl Gleichstand']);
    $round = ElectionRound::factory()->countingRule(ElectionCountingRule::SingleMajority, 1)->create(['election_event_id' => $election->id, 'title' => $title]);
    $ids = [];

    foreach (['alice' => 'Alice Ambrosius', 'bob' => 'Bob Brenner'] as $key => $name) {
        $pleb = EinundzwanzigPleb::factory()->active()->create();
        Profile::factory()->create(['pubkey' => $pleb->pubkey, 'name' => $name]);
        $ids[$key] = (int) ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $pleb->id])->id;
    }

    $round = app(OpenRound::class)->handle($round->fresh());
    castElectionBallot($round, $voters[0], [$ids['alice'] => 'for']);
    castElectionBallot($round, $voters[1], [$ids[$tied ? 'bob' : 'alice'] => 'for']);
    $round = app(CloseRound::class)->handle($round);
    NostrAuth::login($board->pubkey);

    return [$board, $election, $round, $ids];
}

function tieAdmin(): Testable
{
    return Livewire::test('association.election.admin');
}

/**
 * The meeting component of the round's election, where the tie dialogs live.
 */
function tieLive(ElectionRound $round): Testable
{
    return liveControl($round->election);
}

/**
 * @return list<string>
 */
function tieAudit(Election $election): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('action')->map->value->all();
}

function tieShare(Election $election): Testable
{
    return Livewire::test('association.election.screen', ['election' => $election->id]);
}

function tieProtocol(Election $election): string
{
    return Livewire::test('association.election.protocol', ['election' => $election])->instance()->plainText;
}

function tieMemberPage(): Testable
{
    $member = electionVoter();
    electionSecretFor($member);
    NostrAuth::login($member->pubkey);

    return Livewire::test('association.election.index');
}

it('shows an undecided tie with the pending casting vote on every screen, never a run-off', function () {
    [, $election] = tiedEvening();

    tieShare($election)
        ->assertSee('Gleichstand.')
        ->assertSeeHtml('data-test="share-tie-next"')
        ->assertSee('Die/der Vorsitzende fällt den Stichentscheid.')
        ->assertDontSee('Darüber entscheidet eine Stichwahl')
        ->assertDontSeeHtml('data-test="share-casting-vote"');

    $this->get(route('association.election.admin.protocol', $election))->assertOk()
        ->assertSee('Die/der Vorsitzende fällt den Stichentscheid.')
        ->assertDontSee('Darüber entscheidet eine Stichwahl');

    expect(tieProtocol($election))->toContain('Die/der Vorsitzende fällt den Stichentscheid.')
        ->toContain('Alice Ambrosius: 1 Stimme, Gleichstand');

    tieMemberPage()
        ->assertSee('Die/der Vorsitzende fällt den Stichentscheid.')
        ->assertDontSee('Darüber entscheidet eine Stichwahl');
});

it('shows the result with a line naming the chair once the casting vote is recorded', function () {
    [$board, $election, $round, $ids] = tiedEvening();
    app(RecordCastingVote::class)->handle($round, $board, [$ids['bob']], 'Erika Vorsitz', 'Nach Aussprache');
    $line = 'Stichentscheid der/des Vorsitzenden Erika Vorsitz: gewählt ist Bob Brenner.';

    // The share view names the chair only: its outcome line and rows name Bob already.
    tieShare($election)
        ->assertSeeHtml('data-test="share-casting-vote"')
        ->assertSee('Entschieden durch Stichentscheid der/des Vorsitzenden Erika Vorsitz.')
        ->assertDontSee($line)
        ->assertDontSee('Die/der Vorsitzende fällt den Stichentscheid.')
        ->assertDontSeeHtml('data-test="share-tie-next"');

    $this->get(route('association.election.admin.protocol', $election))->assertOk()
        ->assertSee($line)
        ->assertSee('Anmerkung: Nach Aussprache')
        ->assertSee('Stichentscheid eingetragen')
        ->assertSee('Vorsitz: Erika Vorsitz');

    expect(tieProtocol($election))->toContain('Bob Brenner: 1 Stimme, gewählt durch Stichentscheid')
        ->toContain('Ergebnis: gewählt ist Bob Brenner')
        ->toContain($line)
        ->not->toContain('Die/der Vorsitzende fällt den Stichentscheid.');

    // The member page names Bob in its "Gewählt ist" sentence and the chair on
    // a line of its own: one sentence with both ran 7 lines at 390 px (Q12).
    tieMemberPage()
        ->assertSeeHtml('data-test="casting-vote-'.$round->id.'"')
        ->assertSeeInOrder(['Gewählt ist Bob Brenner.', 'Entschieden durch Stichentscheid.', 'Vorsitz: Erika Vorsitz'])
        ->assertDontSee($line);

    NostrAuth::login($board->pubkey);
    tieAdmin()->assertSee($line)
        ->assertSee('Anmerkung: Nach Aussprache')
        ->assertDontSeeHtml("startCastingVote({$round->id})")
        ->assertDontSeeHtml("startTieRunOff({$round->id})")
        ->assertDontSee(runSheetClick('start-casting-vote', $round->id))
        ->assertDontSee(runSheetClick('start-tie-run-off', $round->id));
});

it('says on every screen that the assembly resolved a run-off instead', function () {
    [$board, $election, $round] = tiedEvening();
    app(CreateRunOff::class)->handle($round, $board, resolvedByAssembly: true);
    $sentence = 'Die Versammlung hat statt des Stichentscheids eine Stichwahl beschlossen.';

    expect(tieProtocol($election))->toContain($sentence);
    $this->get(route('association.election.admin.protocol', $election))->assertOk()->assertSee($sentence);
    tieMemberPage()->assertSee($sentence);
    NostrAuth::login($board->pubkey);
    tieAdmin()->assertSee($sentence)
        ->assertDontSeeHtml("startCastingVote({$round->id})")
        ->assertDontSee(runSheetClick('start-casting-vote', $round->id));
});

it('takes the casting vote in the board dialog, with the tied counts, the count rule and the chair', function () {
    [, $election, $round, $ids] = tiedEvening();

    tieLive($round)
        ->call('startCastingVote', $round->id)
        ->assertSee('Stichentscheid zu „Präsidium“')
        ->assertSeeHtml('data-test="casting-candidate-'.$ids['alice'].'"')
        ->assertSeeHtml('data-test="casting-candidate-'.$ids['bob'].'"')
        ->assertSee('1 Stimme')
        ->call('recordCastingVote')
        ->assertHasErrors(['castingCandidates' => 'required', 'castingChair' => 'required'])
        ->set('castingCandidates', [$ids['alice'], $ids['bob']])
        ->set('castingChair', 'Erika Vorsitz')
        ->call('recordCastingVote')
        ->assertHasErrors(['castingCandidates' => 'size'])
        ->assertSee('Wähle genau eine Person: Es geht um einen Sitz.')
        ->set('castingCandidates', [$ids['alice']])
        ->call('recordCastingVote')
        ->assertHasNoErrors()
        ->assertSee('Stichentscheid der/des Vorsitzenden Erika Vorsitz: gewählt ist Alice Ambrosius.');

    expect(ElectionCastingVote::query()->where('election_round_id', $round->id)->sole()->candidate_ids)->toBe([$ids['alice']])
        ->and(app(TallyRound::class)->effective($round)->elected)->toBe([$ids['alice']])
        ->and(tieAudit($election))->toBe(['casting_vote_recorded']);
});

it('refuses a chair name over 60 characters in the dialog with a German error, and takes one of 60', function () {
    [, $election, $round, $ids] = tiedEvening();
    $chair = 'Erika Magdalena Mustermann-Vorsitz von der Leyen-Schwarzwald';

    $page = tieLive($round)
        ->call('startCastingVote', $round->id)
        ->assertSeeHtml('maxlength="60"')
        ->set('castingCandidates', [$ids['alice']])
        ->set('castingChair', $chair.'x')
        ->call('recordCastingVote')
        ->assertHasErrors(['castingChair' => 'max'])
        ->assertSee('Der Name darf höchstens 60 Zeichen lang sein.');

    expect(ElectionCastingVote::query()->where('election_round_id', $round->id)->exists())->toBeFalse();

    $page->set('castingChair', $chair)->call('recordCastingVote')->assertHasNoErrors();

    expect(ElectionCastingVote::query()->where('election_round_id', $round->id)->sole()->chair_name)->toBe($chair)
        ->and(tieAudit($election))->toBe(['casting_vote_recorded']);
});

it('refuses the dialog for a round that is not tied, and for a member', function () {
    [, $election, $round, $ids] = tiedEvening();
    $other = openElectionRound(election: $election);

    tieLive($round)->call('startCastingVote', $other->id)->assertNotFound();

    $page = tieLive($round)->call('startCastingVote', $round->id)->set('castingCandidates', [$ids['alice']])->set('castingChair', 'Erika Vorsitz');
    $member = electionVoter();
    NostrAuth::login($member->pubkey);
    $page->call('recordCastingVote')->assertForbidden();

    expect(ElectionCastingVote::query()->exists())->toBeFalse();
});

/*
 * F3: a round annulled after its count keeps its counted result in the
 * minutes — result, checksum and „ausgezählt“, then „annulliert“ with time
 * and reason, marked as not valid. The round stays annulled.
 */
it('keeps the counted result of a round annulled after its count in the minutes, marked as not valid', function () {
    [$board, $election, $round] = tiedEvening();
    $hash = ElectionManifest::query()->where('election_round_id', $round->id)->value('hash');
    app(ElectionStewardship::class)->annul($round, $board, 'Kandidatin nicht wählbar');
    $html = $this->get(route('association.election.admin.protocol', $election))->assertOk();

    $html->assertSeeInOrder([
        'geschlossen und ausgezählt',
        'Alice Ambrosius',
        '1 Stimme',
        'Ausgezähltes Ergebnis (ungültig): ',
        $hash,
        'ANNULLIERT',
        'Das ausgezählte Ergebnis ist nicht gültig.',
        'Grund: Kandidatin nicht wählbar',
    ])->assertSee('data-test="protocol-void-result-'.$round->id.'"', false);

    expect(tieProtocol($election))
        ->toContain('Alice Ambrosius: 1 Stimme')
        ->toContain('Ausgezähltes Ergebnis (ungültig): ')
        ->toContain('Prüfsumme (SHA-256): '.$hash)
        ->toContain('Das ausgezählte Ergebnis ist nicht gültig.')
        ->toContain('Grund: Kandidatin nicht wählbar')
        ->and(strpos(tieProtocol($election), 'Prüfsumme'))->toBeLessThan(strpos(tieProtocol($election), 'ANNULLIERT'))
        ->and($round->fresh()->annulled_at)->not->toBeNull();
});

it('refuses in the dialog a casting vote for a person elected in another round, naming person and round, and takes the other', function () {
    [, $election, $round, $ids] = tiedEvening();
    $voters = [electionVoter(), electionVoter()];
    $alice = (int) ElectionCandidate::query()->findOrFail($ids['alice'])->einundzwanzig_pleb_id;

    // Alice elected in „Kasse“ while still level here. The application can
    // reach this through an annulled run-off (TieDoubleElectionGuardTest,
    // P1b); here her candidacy is written past the open-time check for brevity.
    $treasury = ElectionRound::factory()->countingRule(ElectionCountingRule::SingleMajority, 1)->withCandidates(1)->create(['election_event_id' => $election->id, 'title' => 'Kasse', 'position' => 5]);
    $treasuryAlice = (int) ElectionCandidate::factory()->status(ElectionCandidateStatus::Withdrawn)->create(['election_round_id' => $treasury->id, 'einundzwanzig_pleb_id' => $alice])->id;
    $treasury = app(OpenRound::class)->handle($treasury->fresh());
    DB::table('election_candidates')->where('id', $treasuryAlice)->update(['status' => ElectionCandidateStatus::Approved->value]);
    castElectionBallot($treasury, $voters[0], [$treasuryAlice => 'for']);
    castElectionBallot($treasury, $voters[1], [$treasuryAlice => 'for']);
    app(CloseRound::class)->handle($treasury);

    $page = tieLive($round)
        ->call('startCastingVote', $round->id)
        ->set('castingCandidates', [$ids['alice']])
        ->set('castingChair', 'Erika Vorsitz')
        ->call('recordCastingVote')
        ->assertHasErrors(['castingCandidates'])
        ->assertSee('Alice Ambrosius ist im Wahlgang „Kasse“ schon gewählt. Die/der Vorsitzende kann nur für eine andere Person aus dem Gleichstand entscheiden.');

    expect(ElectionCastingVote::query()->where('election_round_id', $round->id)->exists())->toBeFalse();

    $page->set('castingCandidates', [$ids['bob']])->call('recordCastingVote')->assertHasNoErrors();

    expect(app(TallyRound::class)->effective($round->fresh())->elected)->toBe([$ids['bob']]);
});

it('refuses in the dialog a casting vote for a person standing in another opened round, naming person and round and what to do', function () {
    [$board, $election, $round, $ids] = tiedEvening();
    $alice = ElectionCandidate::query()->findOrFail($ids['alice'])->pleb;

    // Reached through the services only (DoubleElectionInvariantTest, gate
    // finding D2): „Kasse“ opens with Alice while the run-off the assembly
    // resolved is a draft; Alice is withdrawn from the run-off, which is then
    // opened and annulled, and the tie is the chair's again.
    $runOff = app(CreateRunOff::class)->handle($round, $board, resolvedByAssembly: true);
    $treasury = ElectionRound::factory()->countingRule(ElectionCountingRule::SingleMajority, 1)->create(['election_event_id' => $election->id, 'title' => 'Kasse', 'position' => 5]);
    ElectionCandidate::factory()->create(['election_round_id' => $treasury->id, 'einundzwanzig_pleb_id' => $alice->id]);
    app(OpenRound::class)->handle($treasury->fresh());
    app(Candidacies::class)->withdraw($runOff->candidates()->where('einundzwanzig_pleb_id', $alice->id)->firstOrFail(), $board, 'steht in der Kasse');
    app(ElectionStewardship::class)->annul(app(OpenRound::class)->handle($runOff->fresh()), $board, 'zurück an die Vorsitzende');

    $page = tieLive($round)
        ->call('startCastingVote', $round->id)
        ->set('castingCandidates', [$ids['alice']])
        ->set('castingChair', 'Erika Vorsitz')
        ->call('recordCastingVote')
        ->assertHasErrors(['castingCandidates'])
        ->assertSee('Alice Ambrosius kandidiert auch im Wahlgang „Kasse“, der noch nicht ausgezählt ist. Entscheidet für eine andere Person aus dem Gleichstand, oder entscheidet, nachdem „Kasse“ ausgezählt ist.');

    expect(ElectionCastingVote::query()->where('election_round_id', $round->id)->exists())->toBeFalse();

    $page->set('castingCandidates', [$ids['bob']])->call('recordCastingVote')->assertHasNoErrors();

    expect(app(TallyRound::class)->effective($round->fresh())->elected)->toBe([$ids['bob']]);
});

it('refuses the casting vote with a form error when the round was annulled while the dialog was open', function () {
    [$board, $election, $round, $ids] = tiedEvening();

    $page = tieLive($round)
        ->call('startCastingVote', $round->id)
        ->call('$refresh')->assertOk()
        ->set('castingCandidates', [$ids['alice']])
        ->set('castingChair', 'Erika Vorsitz');

    app(ElectionStewardship::class)->annul($round, $board, 'x');

    $page->call('recordCastingVote')
        ->assertOk()
        ->assertHasErrors('castingCandidates')
        ->assertSee('oder der Wahlgang ist annulliert');

    expect(ElectionCastingVote::query()->exists())->toBeFalse()
        ->and(tieAudit($election))->not->toContain('casting_vote_recorded');
});

it('gives no per-row verdict in the void result of an annulled round, but keeps its counts', function () {
    [$board, $election, $round] = tiedEvening(tied: false);
    app(ElectionStewardship::class)->annul($round, $board, 'Kandidatin nicht wählbar');

    $html = $this->get(route('association.election.admin.protocol', $election))->assertOk()->getContent();
    preg_match('/<table[^>]*data-test="protocol-void-result-'.$round->id.'".*?<\/table>/s', $html, $table);

    expect($table[0] ?? '')->toContain('Alice Ambrosius')
        ->toContain('2 Stimmen')
        ->not->toContain('gewählt');

    // Statutes jury L3: never „gewählt ist“ for an annulled round, in either form.
    expect($html)->toContain('Ausgezähltes Ergebnis (ungültig): die Zählung hätte Alice Ambrosius ergeben')
        ->not->toContain('gewählt ist');

    expect(tieProtocol($election))
        ->toContain('Alice Ambrosius: 2 Stimmen')
        ->not->toContain('Alice Ambrosius: 2 Stimmen, gewählt')
        ->toContain('Ausgezähltes Ergebnis (ungültig): die Zählung hätte Alice Ambrosius ergeben')
        ->not->toContain('gewählt ist');
});

/*
 * Statutes jury M1: the minutes named the association „EINUNDZWANZIG e. V.“,
 * a German registered association. It is a Verein under Art. 60 ff. ZGB
 * named „EINUNDZWANZIG“ (statutes Art. 1).
 */
it('names the association without a legal form it does not have in the minutes', function () {
    [, $election] = tiedEvening();

    $this->get(route('association.election.admin.protocol', $election))->assertOk()
        ->assertSee('Verein EINUNDZWANZIG, Beschlussprotokoll')
        ->assertDontSee('e. V.');
});

/*
 * Member jury M1: a member page already open kept saying the chair would
 * decide, three polls after the casting vote was recorded, because the poll
 * fingerprint left the casting vote out. Mutation that must turn this red:
 * drop the casting vote from `currentFingerprint()`.
 */
it('brings the casting vote to a member page that is already open within one poll', function () {
    [$board, , $round, $ids] = tiedEvening();
    $page = tieMemberPage()->assertDontSeeHtml('data-test="casting-vote-'.$round->id.'"');
    $before = $page->get('fingerprint');

    app(RecordCastingVote::class)->handle($round, $board, [$ids['alice']], 'Erika Mustermann');
    $page->call('syncRounds');

    expect($page->get('fingerprint'))->not->toBe($before);
    $page->assertSeeHtml('data-test="casting-vote-'.$round->id.'"')->assertSee('Erika Mustermann');
});

/**
 * Stand-ins for `RoundReport` and `CreateRunOff` as the security fix of
 * package j2c (ba85b1a) shapes them, which is not on this branch: a round
 * whose stored votes do not fit it reports `unreadable` with no result and
 * no run-off proposal, and its run-off is refused with "cannot be counted".
 * The count itself (`TallyRound`) stays real and refuses the corrupted row.
 */
function fakeUnreadableCountServices(): void
{
    app()->bind(CreateRunOff::class, fn ($app) => new class($app->make(TallyRound::class), $app->make(ElectionAuditLog::class)) extends CreateRunOff
    {
        public function proposal(ElectionRound $round, ?TallyResult $effective = null): array
        {
            try {
                return parent::proposal($round, $effective);
            } catch (UnexpectedValueException) {
                return ['candidates' => [], 'seats' => 0, 'needs_resolution' => false];
            }
        }

        public function handle(ElectionRound $round, EinundzwanzigPleb $actor, bool $resolvedByAssembly = false): ElectionRound
        {
            try {
                $this->tally->handle($round);
            } catch (UnexpectedValueException) {
                throw new ElectionActionRefusedException('The stored votes of this round cannot be counted; check it with election:verify.');
            }

            return parent::handle($round, $actor, $resolvedByAssembly);
        }
    });

    app()->bind(RoundReport::class, fn ($app) => new class($app->make(TallyRound::class), $app->make(RoundManifest::class), $app->make(CreateRunOff::class)) extends RoundReport
    {
        public function of(ElectionRound $round, ?CarbonInterface $now = null): array
        {
            try {
                return parent::of($round, $now) + ['unreadable' => false];
            } catch (UnexpectedValueException) {
                return [
                    'round' => $round, 'state' => $round->stateAt($now ?? now()), 'sealed' => $round->isSealed(), 'over_by_clock' => false,
                    'roll' => 0, 'cast' => 0, 'candidates' => [], 'result' => null, 'manifest' => null, 'void_result' => null,
                    'unreadable' => true, 'reason' => null, 'run_off' => null, 'has_run_off' => false, 'run_off_resolved' => false,
                ];
            }
        }
    });
}

function corruptStoredVotes(ElectionRound $round): void
{
    DB::table('election_votes')->where('election_round_id', $round->id)->update(['choices' => json_encode(['999999' => 'for'])]);
}

/*
 * Package j (security jury L1, on top of j2c): a tied round whose stored
 * votes no longer fit it. The tie dialogs counted it uncaught and answered
 * 500; now they say „Ergebnis nicht lesbar“ and point to election:verify.
 * Mutation that must turn this red: drop the UnexpectedValueException catch
 * in tieRound() or in recordCastingVote().
 */
it('answers a tie dialog on a round with an unreadable count with the verify hint instead of a 500', function () {
    fakeUnreadableCountServices();
    [, , $round, $ids] = tiedEvening();
    $hint = 'Ergebnis nicht lesbar: Die gespeicherten Stimmen passen nicht zu diesem Wahlgang. Prüfen mit „php artisan election:verify '.$round->id.'“.';

    // The dialog was already open when the row broke.
    $page = tieLive($round)->call('startCastingVote', $round->id)->set('castingCandidates', [$ids['alice']])->set('castingChair', 'Erika Vorsitz');
    corruptStoredVotes($round);

    $page->call('recordCastingVote')->assertOk()->assertHasErrors(['castingCandidates'])->assertSee($hint);
    expect(ElectionCastingVote::query()->where('election_round_id', $round->id)->exists())->toBeFalse();

    // Opening either dialog afresh: a toast, no dialog, and the live list marks the round.
    foreach (['startCastingVote', 'startTieRunOff'] as $action) {
        tieLive($round)->call($action, $round->id)->assertOk()
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['dataset']['variant'] === 'danger' && $params['slots']['text'] === $hint)
            ->assertSet('tieRoundId', null)
            ->assertSeeHtml('data-test="live-unreadable-'.$round->id.'"')
            ->assertDontSeeHtml('data-test="live-outcome-'.$round->id.'"');
    }
});

it('names the unreadable count when the run-off of such a round is refused', function () {
    fakeUnreadableCountServices();
    [, , $round] = tiedEvening();
    $page = tieLive($round)->call('startTieRunOff', $round->id)->set('runOffResolved', true);
    corruptStoredVotes($round);
    $refusal = 'Für „Präsidium“ lässt sich keine Stichwahl anlegen: Ergebnis nicht lesbar. Prüft den Wahlgang mit „php artisan election:verify '.$round->id.'“ oder annulliert ihn.';

    $page->call('confirmTieRunOff')->assertOk()->assertHasErrors(['runOffResolved'])->assertSee($refusal);

    tieLive($round)->call('createRunOff', $round->id)->assertOk()
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => $params['dataset']['variant'] === 'danger' && $params['slots']['text'] === $refusal);
    expect(ElectionRound::query()->where('parent_round_id', $round->id)->exists())->toBeFalse();
});

/*
 * OpenRound (j2c) refuses a round whose candidate stands in a sealed round
 * with an unreadable count. The exception names the member, not the round,
 * so the page finds that round itself. Faked exception; the count is real.
 */
it('names the earlier round with an unreadable count when opening is refused for its candidate', function () {
    fakeUnreadableCountServices();
    [, , $round, $ids] = tiedEvening();
    corruptStoredVotes($round);
    $member = (int) ElectionCandidate::query()->findOrFail($ids['alice'])->einundzwanzig_pleb_id;

    $text = app(RefusalText::class)->of('open', new ElectionCandidateBlockedException(
        'A candidate of this round stands in an earlier round of the election whose stored votes cannot be counted; check it with election:verify.',
        $member,
    ), $round->election);

    expect($text)->toBe('Alice Ambrosius kandidiert auch in „Präsidium“, und dessen Ergebnis ist nicht lesbar. Prüft ihn mit „php artisan election:verify '.$round->id.'“ oder annulliert ihn.')
        ->not->toContain('Lade die Seite neu');
});

it('decides a yes = no tie with Nein only when the chair confirms it, never by an empty submit', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->announced()->create(['name' => 'Vorstandswahl Ja Nein']);
    $round = ElectionRound::factory()->countingRule(ElectionCountingRule::YesNoPerCandidate, 1)->create(['election_event_id' => $election->id, 'title' => 'Kasse']);
    $pleb = EinundzwanzigPleb::factory()->active()->create();
    Profile::factory()->create(['pubkey' => $pleb->pubkey, 'name' => 'Gerd Gruber']);
    $gerd = (int) ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $pleb->id])->id;
    $voters = [electionVoter(), electionVoter()];
    $round = app(OpenRound::class)->handle($round->fresh());
    castElectionBallot($round, $voters[0], [$gerd => 'yes']);
    castElectionBallot($round, $voters[1], [$gerd => 'no']);
    $round = app(CloseRound::class)->handle($round);
    NostrAuth::login($board->pubkey);

    tieLive($round)
        ->call('startCastingVote', $round->id)
        ->assertSeeHtml('data-test="casting-all-no"')
        ->set('castingChair', 'Erika Vorsitz')
        ->call('recordCastingVote')
        ->assertHasErrors(['castingCandidates'])
        ->assertSee('Niemand ist angehakt.')
        ->set('castingCandidates', [$gerd])
        ->set('castingAllNo', true)
        ->call('recordCastingVote')
        ->assertHasErrors(['castingCandidates'])
        ->assertSee('Entscheide dich für eines.');

    expect(ElectionCastingVote::query()->where('election_round_id', $round->id)->exists())->toBeFalse();

    tieLive($round)
        ->call('startCastingVote', $round->id)
        ->set('castingChair', 'Erika Vorsitz')
        ->set('castingAllNo', true)
        ->call('recordCastingVote')
        ->assertHasNoErrors();

    expect(ElectionCastingVote::query()->where('election_round_id', $round->id)->sole()->candidate_ids)->toBe([])
        ->and(app(TallyRound::class)->effective($round)->elected)->toBe([]);
});
