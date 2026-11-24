<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Models\Profile;
use App\Services\Election\ElectionStewardship;
use App\Services\Election\ElectoralRoll;
use App\Support\NostrAuth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';
require_once __DIR__.'/helpers.php';

/*
 * The electoral roll of an election on the board's page, a child component
 * of its own: board only, counts and names, never votes, and a member
 * enrolled or entitled by hand leaves an audit line. The page follows
 * through the `election-changed` event.
 */

function electionRoll(Election $election): Testable
{
    return Livewire::test('association.election.admin-roll', ['electionId' => $election->id]);
}

/**
 * @return list<string>
 */
function rollAudit(Election $election): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('action')->map->value->all();
}

it('answers 403 on every public method to a member who is not, or no longer, on the board', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->announced()->create();
    openElectionRound(election: $election);
    $outsider = EinundzwanzigPleb::factory()->active()->create();
    $member = electionVoter();

    $methods = expectEveryMethodBoardOnly(
        fn () => electionRoll($election)
            ->set('enrolMemberId', $outsider->id)->set('enrolReason', 'Grund')
            ->set('entitleMemberId', $outsider->id)->set('entitleReason', 'Grund'),
        $board,
        $member,
        [],
    );

    expect($methods)->toBe(['election', 'enrol', 'enrolCandidates', 'entitle', 'entitleCandidates', 'openedRounds', 'rollEntries', 'rollPreview', 'rollRound'])
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $outsider->id)->exists())->toBeFalse()
        ->and(ElectionAuditEntry::query()->count())->toBe(0);

    NostrAuth::login($member->pubkey);
    electionRoll($election)->assertForbidden();
});

it('shows each entitled member with the reason and who signed in since the announcement', function () {
    // 23:30 UTC is already the next day in the display timezone.
    $this->travelTo(now()->setTime(23, 30));
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $election = Election::factory()->create(['announced_at' => now()->subDays(3)]);
    draftElectionRound(candidates: 0, election: $election);
    electionVoter()->forceFill(['last_login_at' => now()->subDay()])->save();
    electionVoter()->forceFill(['last_login_at' => now()->subWeek()])->save();
    EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::HONORARY]);

    electionRoll($election)
        ->assertSee('3 von 4 Aktiv- und Ehrenmitgliedern sind wahlberechtigt.')
        ->assertSee('Davon hat sich eine Person seit der Ankündigung angemeldet.')
        ->assertSee('Aktivmitglied, Beitrag bezahlt')
        ->assertSee('Ja, '.now()->subDay()->setTimezone(config('app.display_timezone'))->format('d.m.Y'))
        ->assertSee('Beitrag '.now()->year.' fehlt');

    // The page shows the same roll in its tab.
    Livewire::test('association.election.admin')
        ->call('showSection', 'roll')
        ->assertSee('3 von 4 Aktiv- und Ehrenmitgliedern sind wahlberechtigt.');
});

it('enrols a member by hand in an open round with a reason and tells the page', function () {
    $board = electionBoard(1)[0];
    NostrAuth::login($board->pubkey);
    $election = Election::factory()->announced()->create();
    $round = openElectionRound(election: $election);
    $member = EinundzwanzigPleb::factory()->active()->create();

    electionRoll($election)
        ->assertSee('Mitglied von Hand nachtragen')
        ->set('enrolMemberId', $member->id)
        ->call('enrol')
        ->assertHasErrors(['enrolReason'])
        ->assertNotDispatched('election-changed')
        ->set('enrolReason', 'Beitrag bar bezahlt')
        ->call('enrol')
        ->assertHasNoErrors()
        ->assertDispatched('election-changed')
        ->assertSet('enrolMemberId', null);

    $entry = ElectionRollEntry::query()->where('election_round_id', $round->id)->where('einundzwanzig_pleb_id', $member->id)->sole();

    expect($entry->reason)->toBe(ElectionRollReason::ConfirmedByBoard)
        ->and($entry->source)->toBe(ElectionRollSource::ByHand)
        ->and(rollAudit($election))->toBe(['voter_enrolled_by_hand'])
        ->and(ElectionAuditEntry::query()->sole()->only(['actor_pleb_id', 'subject_pleb_id']))->toBe(['actor_pleb_id' => $board->id, 'subject_pleb_id' => $member->id]);
});

it('shows the roll of the chosen opened round only, never of another election', function () {
    [$board] = electionBoard(1);
    NostrAuth::login($board->pubkey);
    $election = Election::factory()->test()->announced()->create();
    $first = openElectionRound(['title' => 'Präsidium', 'position' => 1], election: $election);
    app(ElectionStewardship::class)->closeEarly($first, $board, 'Fertig');
    $second = openElectionRound(['title' => 'Vorstand', 'position' => 2], election: $election);
    $foreign = openElectionRound(['title' => 'Fremd'], election: Election::factory()->test()->announced()->create());

    electionRoll($election)
        ->assertSee('Im Verzeichnis von „Vorstand“')
        ->set('rollRoundId', $first->id)
        ->assertSee('Im Verzeichnis von „Präsidium“')
        ->set('rollRoundId', $foreign->id)
        ->assertSee('Im Verzeichnis von „Vorstand“')
        ->assertDontSee('Fremd');

    expect($second->fresh()->opened_at)->not->toBeNull();
});

/*
 * Cash payer on the page: the approval refusal points to the roll, the roll
 * tab enters the entitlement with a reason, and then the approval works.
 */
it('enters a cash payer as entitled from the roll tab, after which the candidacy can be approved', function () {
    $board = electionBoard(1)[0];
    NostrAuth::login($board->pubkey);
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(['title' => 'Kasse'], candidates: 0, election: $election);
    $cashPayer = EinundzwanzigPleb::factory()->active()->create();
    Profile::factory()->create(['pubkey' => $cashPayer->pubkey, 'name' => 'Erik Barzahler']);
    $candidacy = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $cashPayer->id]);

    $candidacies = Livewire::test('association.election.admin-candidacies', ['electionId' => $election->id])
        ->call('approve', $candidacy->id);

    $roll = electionRoll($election)
        ->assertSeeHtml('data-test="entitle-form"')
        ->call('entitle')
        ->assertHasErrors(['entitleMemberId', 'entitleReason'])
        ->set('entitleMemberId', $cashPayer->id)
        ->set('entitleReason', 'Beitrag bar bezahlt am 3. März')
        ->call('entitle')
        ->assertHasNoErrors()
        ->assertDispatched('election-changed');

    expect(app(ElectoralRoll::class)->reasonFor($election, $cashPayer, now()))->toBe(ElectionRollReason::ConfirmedByBoard)
        ->and(rollAudit($election))->toBe(['voter_enrolled_by_hand']);

    $candidacies->call('approve', $candidacy->id);

    $roll->set('entitleMemberId', $cashPayer->id)->set('entitleReason', 'Noch einmal')->call('entitle')
        ->assertHasErrors(['entitleMemberId'])
        ->assertSee('Erik Barzahler ist schon wahlberechtigt.');

    expect($candidacy->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(rollAudit($election))->toBe(['voter_enrolled_by_hand', 'candidacy_approved'])
        ->and(ElectionAuditEntry::query()->where('action', 'voter_enrolled_by_hand')->sole()->actor_pleb_id)->toBe($board->id);
});
