<?php

use App\Enums\ElectionCountingRule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionCastingVote;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Models\Profile;
use App\Services\Election\CloseRound;
use App\Services\Election\CreateRunOff;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\OpenRound;
use App\Services\Election\RecordCastingVote;
use App\Services\Election\TallyRound;
use App\Support\NostrAuth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';

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
        ->assertDontSeeHtml("startTieRunOff({$round->id})");
});

it('says on every screen that the assembly resolved a run-off instead', function () {
    [$board, $election, $round] = tiedEvening();
    app(CreateRunOff::class)->handle($round, $board, resolvedByAssembly: true);
    $sentence = 'Die Versammlung hat statt des Stichentscheids eine Stichwahl beschlossen.';

    expect(tieProtocol($election))->toContain($sentence);
    $this->get(route('association.election.admin.protocol', $election))->assertOk()->assertSee($sentence);
    tieMemberPage()->assertSee($sentence);
    NostrAuth::login($board->pubkey);
    tieAdmin()->assertSee($sentence)->assertDontSeeHtml("startCastingVote({$round->id})");
});

it('takes the casting vote in the board dialog, with the tied counts, the count rule and the chair', function () {
    [, $election, $round, $ids] = tiedEvening();

    tieAdmin()
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

    $page = tieAdmin()
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

    tieAdmin()->call('startCastingVote', $other->id)->assertNotFound();

    $page = tieAdmin()->call('startCastingVote', $round->id)->set('castingCandidates', [$ids['alice']])->set('castingChair', 'Erika Vorsitz');
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

it('refuses the casting vote with a form error when the round was annulled while the dialog was open', function () {
    [$board, $election, $round, $ids] = tiedEvening();

    $page = tieAdmin()
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

    expect(tieProtocol($election))
        ->toContain('Alice Ambrosius: 2 Stimmen')
        ->not->toContain('Alice Ambrosius: 2 Stimmen, gewählt')
        ->toContain('Ausgezähltes Ergebnis (ungültig): gewählt ist Alice Ambrosius');
});
