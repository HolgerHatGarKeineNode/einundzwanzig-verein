<?php

use App\Enums\ElectionCountingRule;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Services\Election\OpenRound;
use App\Support\NostrAuth;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';
require_once __DIR__.'/helpers.php';

/*
 * The settings of an election on the board's page, a child component of its
 * own: a new draft or the selected election, board only, and every save goes
 * through ElectionSetup with its audit line. The page shows a stored draft
 * and follows an edit through the `election-created` and `election-changed`
 * events.
 */

function electionForm(?Election $election = null): Testable
{
    return Livewire::test('association.election.admin-form', ['electionId' => $election?->id]);
}

/**
 * @return list<string>
 */
function electionFormAudit(Election $election): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('action')->map->value->all();
}

it('answers 403 on every public method to a member who is not, or no longer, on the board', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create(['name' => 'Alt']);
    draftElectionRound(candidates: 0, election: $election);
    $member = electionVoter();

    $methods = expectEveryMethodBoardOnly(
        fn () => electionForm($election)->set('form.name', 'Neu'),
        $board,
        $member,
        ['index' => 0],
    );

    expect($methods)->toBe(['addRound', 'election', 'removeRound', 'save', 'saveMeeting'])
        ->and($election->fresh()->name)->toBe('Alt')
        ->and(ElectionAuditEntry::query()->count())->toBe(0);

    NostrAuth::login($member->pubkey);
    electionForm()->assertForbidden();
});

it('creates an election with the three prepared live rounds, writes the audit line and hands it to the page', function () {
    $board = electionBoard(1)[0];
    NostrAuth::login($board->pubkey);

    $form = electionForm()
        ->set('form.name', 'Vorstandswahl 2027')
        ->set('form.meeting_date', '2027-03-20')
        ->set('form.meeting_time', '19:30')
        ->set('form.call_url', 'https://meet.example.org/vorstandswahl')
        ->set('form.is_test', true)
        ->call('save')
        ->assertHasNoErrors();

    $election = Election::query()->sole();
    $form->assertDispatched('election-created', electionId: $election->id);

    expect($election->name)->toBe('Vorstandswahl 2027')
        ->and($election->meeting_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2027-03-20 19:30')
        ->and($election->call_url)->toBe('https://meet.example.org/vorstandswahl')
        ->and($election->is_test)->toBeTrue()
        ->and($election->rounds->pluck('title')->all())->toBe(['Präsidium', 'Vorstand', 'Revisionsstelle'])
        ->and(electionFormAudit($election))->toBe(['election_created'])
        ->and(ElectionAuditEntry::query()->value('actor_pleb_id'))->toBe($board->id);

    // The hand-off: the page closes the create panel and shows the new draft.
    Livewire::test('association.election.admin')
        ->call('startCreate')
        ->assertSet('creating', true)
        ->assertSeeHtml('data-test="create-panel"')
        ->dispatch('election-created', electionId: $election->id)
        ->assertSet('creating', false)
        ->assertSet('selectedElectionId', $election->id)
        ->assertDontSeeHtml('data-test="create-panel"')
        ->assertSee('Wahl ankündigen');
});

it('refuses an empty name, a single-majority round with more than one seat and a call link that is no URL', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);

    electionForm()
        ->set('form.name', '')
        ->call('save')
        ->assertHasErrors(['form.name'])
        ->assertNotDispatched('election-created');

    electionForm()
        ->set('form.name', 'Vorstandswahl 2027')
        ->set('form.rounds.0.seats', 2)
        ->call('save')
        ->assertHasErrors(['form.rounds.0.seats']);

    electionForm()
        ->set('form.name', 'Vorstandswahl 2027')
        ->set('form.call_url', 'meet.example.org/ohne-schema')
        ->call('save')
        ->assertHasErrors(['form.call_url'])
        ->assertSee('Der Link zum Video-Call muss eine vollständige Adresse sein');

    expect(Election::query()->count())->toBe(0);
});

it('edits a draft election, tells the page, and refuses after a round has been opened', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $election = Election::factory()->create(['name' => 'Alt']);
    $round = draftElectionRound(['title' => 'Präsidium'], candidates: 1, election: $election);

    electionForm($election)
        ->assertSet('form.name', 'Alt')
        ->set('form.name', 'Neu')
        ->set('form.rounds.0.counting_rule', ElectionCountingRule::YesNoPerCandidate->value)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('election-changed');

    expect($election->fresh()->name)->toBe('Neu')
        ->and($round->fresh()->counting_rule)->toBe(ElectionCountingRule::YesNoPerCandidate)
        ->and(electionFormAudit($election))->toBe(['election_updated']);

    // The hand-off: the page reads the new name on the event.
    $page = Livewire::test('association.election.admin');
    $election->update(['name' => 'Von nebenan']);
    $page->dispatch('election-changed')->assertSee('Von nebenan');

    $form = electionForm($election);
    app(OpenRound::class)->handle($round->fresh());

    $form->set('form.name', 'Zu spät')->call('save')->assertHasErrors(['form'])->assertNotDispatched('election-changed');

    expect($election->fresh()->name)->toBe('Von nebenan');
});

it('keeps the meeting time and the call link editable after a round opened, and nothing else', function () {
    $board = electionBoard(1)[0];
    NostrAuth::login($board->pubkey);
    $election = Election::factory()->announced()->create(['name' => 'Fest', 'meeting_at' => '2027-03-20 18:00:00', 'call_url' => 'https://meet.example.org/alt']);
    openElectionRound(election: $election);

    electionForm($election)
        ->assertSee('Termin und Link speichern')
        ->set('form.meeting_time', '20:15')
        ->set('form.call_url', 'https://meet.example.org/neu')
        ->call('saveMeeting')
        ->assertHasNoErrors()
        ->assertDispatched('election-changed')
        ->set('form.call_url', 'kein-link')
        ->call('saveMeeting')
        ->assertHasErrors(['form.call_url'])
        ->set('form.call_url', 'https://meet.example.org/neu')
        ->set('form.name', 'Umbenannt')
        ->call('save')
        ->assertHasErrors(['form']);

    $election->refresh();

    expect($election->call_url)->toBe('https://meet.example.org/neu')
        ->and($election->meeting_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-20 19:15')
        ->and($election->name)->toBe('Fest')
        ->and(electionFormAudit($election))->toBe(['election_meeting_updated'])
        ->and(ElectionAuditEntry::query()->value('actor_pleb_id'))->toBe($board->id);
});

it('shows and reads every time in German time while storing UTC', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    // 20 March 2027 is winter time (UTC+1), 20 June summer time (UTC+2).
    $winter = Election::factory()->create(['name' => 'Winter', 'meeting_at' => Carbon::parse('2027-03-20 18:30', 'UTC')]);
    draftElectionRound(election: $winter);

    Livewire::test('association.election.admin')->assertSee('Sa, 20. März 2027, 19:30 Uhr');

    electionForm($winter)
        ->assertSet('form.meeting_time', '19:30')
        ->set('form.meeting_date', '2027-06-20')
        ->set('form.meeting_time', '19:30')
        ->call('save')
        ->assertHasNoErrors();

    expect($winter->fresh()->meeting_at->utc()->format('Y-m-d H:i'))->toBe('2027-06-20 17:30');
});

it('stores 19:00 entered by the board as 18:00 UTC in March and shows 19:00 on both pages', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $election = Election::factory()->announced()->create(['name' => 'Märzsitzung']);
    draftElectionRound(election: $election);

    electionForm($election)
        ->set('form.meeting_date', '2027-03-20')
        ->set('form.meeting_time', '19:00')
        ->call('save')
        ->assertHasNoErrors();

    expect($election->fresh()->meeting_at->utc()->format('Y-m-d H:i'))->toBe('2027-03-20 18:00');

    Livewire::test('association.election.admin')->assertSee('20. März 2027, 19:00 Uhr');
    electionForm($election)->assertSet('form.meeting_time', '19:00');

    NostrAuth::login(electionVoter()->pubkey);
    Livewire::test('association.election.index')->assertSee('Am 20.03.2027 um 19:00 Uhr')->assertDontSee('um 18:00 Uhr');
});

it('adds rounds up to six and removes one without a candidacy', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);

    $form = electionForm()->call('addRound')->call('addRound')->call('addRound');

    expect($form->get('form.rounds'))->toHaveCount(6);

    $form->call('addRound')->assertCount('form.rounds', 6)->assertDontSeeHtml('data-test="add-round"')
        ->call('removeRound', 0)->assertCount('form.rounds', 5)->assertSet('form.rounds.0.title', 'Vorstand');
});
