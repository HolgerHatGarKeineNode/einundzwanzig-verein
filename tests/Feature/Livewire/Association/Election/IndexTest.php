<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Models\Profile;
use App\Services\Election\OpenRound;
use App\Support\NostrAuth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../Services/Election/helpers.php';

function electionPage(EinundzwanzigPleb $viewer): Testable
{
    NostrAuth::login($viewer->pubkey);

    return Livewire::test('association.election.index');
}

if (! function_exists('primaryButtons')) {
    /**
     * How many elements carry the primary button variant in the rendered page.
     */
    function primaryButtons(Testable $page): int
    {
        return preg_match_all('/<(?:a|button)\b[^>]*class="[^"]*bg-orange-primary[^"]*"/', $page->html());
    }
}

it('says plainly that no election is announced', function () {
    Election::factory()->create(['name' => 'Noch geheime Wahl']);

    electionPage(electionVoter())
        ->assertSee('Gerade steht keine Wahl an')
        ->assertDontSee('Noch geheime Wahl')
        ->assertDontSee('Deine Wahlberechtigung')
        ->assertSee('Signer ausprobieren');
});

it('shows an announced election without offices yet as its first step', function () {
    Election::factory()->announced()->create(['name' => 'Vorstandswahl 2027']);

    $page = electionPage(electionVoter())
        ->assertSee('Vorstandswahl 2027')
        ->assertSee('Der Vorstand legt gerade die Ämter fest')
        ->assertDontSee('Kandidatur einreichen');

    expect($page->instance()->phase)->toBe('announced')
        ->and(primaryButtons($page))->toBe(0);
});

it('lets an entitled member submit a candidacy as the one primary action', function () {
    $election = Election::factory()->announced()->create();
    $round = ElectionRound::factory()->create(['election_event_id' => $election->id, 'title' => 'Präsidium']);
    $member = electionVoter();

    $page = electionPage($member)
        ->assertSee('Du darfst wählen.')
        ->assertSee('Kandidatur einreichen')
        ->assertDontSee('Beitrag bezahlen');

    expect($page->instance()->phase)->toBe('candidacy')
        ->and($page->instance()->primaryAction)->toBe('candidacy')
        ->and(primaryButtons($page))->toBe(1);

    $page->set('statement', "Ich kümmere mich um die Finanzen.\nSeit 2019 dabei.")
        ->call('submitCandidacy')
        ->assertHasNoErrors()
        ->assertSee('Deine Kandidatur: Präsidium')
        ->assertSee('Wartet auf Freigabe')
        ->assertDontSee('Du willst mitgestalten?');

    $candidacy = ElectionCandidate::query()->sole();

    expect($candidacy->einundzwanzig_pleb_id)->toBe($member->id)
        ->and($candidacy->election_round_id)->toBe($round->id)
        ->and($candidacy->status)->toBe(ElectionCandidateStatus::Pending)
        ->and($candidacy->statement)->toBe("Ich kümmere mich um die Finanzen.\nSeit 2019 dabei.");
});

it('asks for a statement and for the office when there is a choice', function () {
    $election = Election::factory()->announced()->create();
    ElectionRound::factory()->count(2)->create(['election_event_id' => $election->id]);

    electionPage(electionVoter())
        ->call('submitCandidacy')
        ->assertHasErrors(['roundId' => 'required', 'statement' => 'required']);

    expect(ElectionCandidate::query()->count())->toBe(0);
});

it('refuses a candidacy for an office of another election', function () {
    $election = Election::factory()->announced()->create();
    ElectionRound::factory()->count(2)->create(['election_event_id' => $election->id]);
    $foreign = ElectionRound::factory()->create();

    electionPage(electionVoter())
        ->set('roundId', $foreign->id)
        ->set('statement', 'Hallo')
        ->call('submitCandidacy')
        ->assertHasErrors(['roundId' => 'in']);

    expect(ElectionCandidate::query()->count())->toBe(0);
});

it('lets the member withdraw their own candidacy, writes it down, and offers no second one', function () {
    $election = Election::factory()->announced()->create();
    $round = ElectionRound::factory()->create(['election_event_id' => $election->id, 'title' => 'Revisionsstelle']);
    $member = electionVoter();
    $own = ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $member->id, 'statement' => 'Ich prüfe gern.']);

    $page = electionPage($member)
        ->assertSee('Freigegeben')
        ->assertSee('Kandidatur zurückziehen');

    expect($page->instance()->withdrawableIds)->toBe([$own->id]);

    $page->call('withdrawCandidacy', $own->id)
        ->assertSee('Zurückgezogen')
        ->assertDontSee('Kandidatur zurückziehen')
        ->assertDontSee('Kandidatur einreichen');

    expect($own->fresh()->status)->toBe(ElectionCandidateStatus::Withdrawn)
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::CandidacyWithdrawn->value)->where('actor_pleb_id', $member->id)->exists())->toBeTrue();
});

it('ignores a withdrawal of somebody else\'s candidacy', function () {
    $election = Election::factory()->announced()->create();
    $round = ElectionRound::factory()->create(['election_event_id' => $election->id]);
    $other = ElectionCandidate::factory()->create(['election_round_id' => $round->id]);

    electionPage(electionVoter())->call('withdrawCandidacy', $other->id);

    expect($other->fresh()->status)->toBe(ElectionCandidateStatus::Approved);
});

it('tells a member without the fee why and sends them to the payment with a way back', function () {
    $this->travelTo('2027-02-01');
    $election = Election::factory()->announced()->feeRule(ElectionFeeRule::Year, 2027)->create();
    ElectionRound::factory()->create(['election_event_id' => $election->id]);
    $member = EinundzwanzigPleb::factory()->active()->withPaidYear(2026)->create();

    $page = electionPage($member)
        ->assertSee('Du darfst noch nicht wählen.')
        ->assertSee('den Beitrag für 2027 bezahlt')
        ->assertSee('Beitrag bezahlen')
        ->assertSee(route('association.profile', ['from' => 'election']), false);

    expect($page->instance()->primaryAction)->toBe('pay')
        ->and(primaryButtons($page))->toBe(1);
});

it('lets a member without the fee still submit, as a secondary action, approved after payment', function () {
    $election = Election::factory()->announced()->create();
    ElectionRound::factory()->create(['election_event_id' => $election->id]);
    $member = EinundzwanzigPleb::factory()->active()->create();

    electionPage($member)
        ->set('statement', 'Ich will helfen.')
        ->call('submitCandidacy')
        ->assertHasNoErrors()
        ->assertSee('Der Vorstand gibt sie frei, sobald dein Beitrag bezahlt ist.');
});

it('does not send a passive member to pay, because paying would not help', function () {
    $election = Election::factory()->announced()->create();
    ElectionRound::factory()->create(['election_event_id' => $election->id]);
    $passive = EinundzwanzigPleb::factory()->withPaidCurrentYear()->create(['association_status' => 2]);

    $page = electionPage($passive)
        ->assertSee('Wählen dürfen aktive Mitglieder und Ehrenmitglieder.')
        ->assertDontSee('Beitrag bezahlen')
        ->assertSee('Kandidieren können aktive Mitglieder und Ehrenmitglieder.');

    expect($page->instance()->ineligibleBecause)->toBe('category');
});

it('says when the required fee cannot be paid in the portal', function () {
    $this->travelTo('2027-02-01');
    $election = Election::factory()->announced()->feeRule(ElectionFeeRule::Year, 2026)->create();
    $member = EinundzwanzigPleb::factory()->active()->create();

    electionPage($member)
        ->assertSee('Das geht nicht über das Portal')
        ->assertDontSee('Beitrag bezahlen');
});

it('hides a test election from members and marks it for the board', function () {
    Election::factory()->test()->create(['name' => 'Probewahl Frühling']);
    $board = electionBoard(1)[0];

    electionPage(electionVoter())
        ->assertDontSee('Probewahl Frühling')
        ->assertSee('Gerade steht keine Wahl an');

    electionPage($board)
        ->assertSee('Probewahl Frühling')
        ->assertSee('Testwahl.')
        ->assertSee('Nur der Vorstand sieht sie.');
});

it('lets the board switch between the test election and the real one', function () {
    $real = Election::factory()->announced()->create(['name' => 'Vorstandswahl 2027']);
    $test = Election::factory()->test()->create(['name' => 'Probewahl']);
    $board = electionBoard(1)[0];

    NostrAuth::login($board->pubkey);

    Livewire::withQueryParams(['wahl' => $real->id])->test('association.election.index')
        ->assertSee('Vorstandswahl 2027')
        ->assertDontSee('Testwahl.');

    Livewire::withQueryParams(['wahl' => $test->id])->test('association.election.index')
        ->assertSee('Testwahl.');
});

it('lists approved candidates with name, picture and statement, and no pending ones', function () {
    $election = Election::factory()->announced()->create();
    $round = ElectionRound::factory()->countingRule(ElectionCountingRule::SingleMajority, 1)->create(['election_event_id' => $election->id, 'title' => 'Präsidium']);
    $approved = EinundzwanzigPleb::factory()->active()->create();
    Profile::factory()->create(['pubkey' => $approved->pubkey, 'name' => 'Satoshi Vorstand', 'picture' => 'https://example.test/satoshi.png']);
    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $approved->id, 'statement' => 'Für ein offenes Portal.']);
    $pending = EinundzwanzigPleb::factory()->active()->create();
    Profile::factory()->create(['pubkey' => $pending->pubkey, 'name' => 'Wartender Kandidat']);
    ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $pending->id]);

    electionPage(electionVoter())
        ->assertSee('Kandidierende')
        ->assertSee('Satoshi Vorstand')
        ->assertSee('https://example.test/satoshi.png', false)
        ->assertSee('Für ein offenes Portal.')
        ->assertSee('1 Sitz')
        ->assertDontSee('Wartender Kandidat');
});

it('moves to the meeting once a round is opened and offers no candidacy any more', function () {
    $election = Election::factory()->announced()->create();
    $round = ElectionRound::factory()->withCandidates()->create(['election_event_id' => $election->id, 'title' => 'Präsidium']);
    $member = electionVoter();
    app(OpenRound::class)->handle($round);

    $page = electionPage($member)
        ->assertSee('Stimmzettel')
        ->assertSee('Offen')
        ->assertDontSee('Kandidatur einreichen');

    // The ballot's "Prüfen" is the one primary action; "Signieren und
    // abgeben" waits hidden in the review step of the same ballot.
    expect($page->instance()->phase)->toBe('meeting')
        ->and($page->instance()->primaryAction)->toBe('ballot');
});

it('shows outsiders the members-only notice', function () {
    Election::factory()->announced()->create(['name' => 'Vorstandswahl 2027']);

    Livewire::test('association.election.index')
        ->assertSee('Die Wahl ist nur für Mitglieder sichtbar')
        ->assertDontSee('Vorstandswahl 2027');
});

it('offers a guest the login on the page itself, with German signer help and the nsec warning', function () {
    Livewire::test('association.election.index')
        ->assertSeeLivewire('auth-button')
        ->assertSee('Mit Nostr anmelden')
        ->assertSee('Noch kein Nostr-Signer?')
        ->assertSee('Am Android-Telefon')
        ->assertSee('Gib nie deinen nsec in eine Webseite ein');
});

it('logs in only through the auth-button whose button started the login', function () {
    $sidebar = Livewire::test('auth-button', ['location' => 'sidebar']);

    // The election page's login reaches the sidebar instance too; it leaves
    // the event alone, so the spent challenge is not verified a second time.
    $sidebar->dispatch('nostrLoggedIn', signedEvent: ['id' => 'not-signed'], location: 'election')
        ->assertHasNoErrors();

    expect(NostrAuth::check())->toBeFalse();

    // Its own login is verified (and this unsigned one refused).
    $sidebar->dispatch('nostrLoggedIn', signedEvent: ['id' => 'not-signed'], location: 'sidebar')
        ->assertHasErrors('nostr');
});
