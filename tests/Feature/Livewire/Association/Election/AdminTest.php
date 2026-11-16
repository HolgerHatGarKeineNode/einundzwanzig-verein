<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Models\Profile;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\OpenRound;
use App\Support\NostrAuth;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';

/*
 * The board's election page: board only, and every action goes through the
 * election services, so each leaves its audit line.
 */

function electionAdmin(): Testable
{
    return Livewire::test('association.election.admin');
}

/**
 * Log in a board of one and return its member.
 */
function loggedInBoard(): EinundzwanzigPleb
{
    [$board] = electionBoard(1);
    NostrAuth::login($board->pubkey);

    return $board;
}

/**
 * @return list<string>
 */
function electionAuditOf(Election $election): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('action')->map->value->all();
}

/**
 * Every public method the page declares itself, read off the component — an
 * action added later is covered the day it is written.
 *
 * @return list<string>
 */
function electionAdminMethods(object $instance): array
{
    return collect((new ReflectionObject($instance))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isStatic())
        ->filter(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === $instance::class)
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->reject(fn (string $name) => $name === 'mount' || str_starts_with($name, 'updated'))
        ->sort()
        ->values()
        ->all();
}

it('is a board page: a member gets 403, a guest a sign-in hint without data, the board the page', function () {
    [$board] = electionBoard(1);
    Election::factory()->create(['name' => 'Vorstandswahl Geheimtest']);
    $member = electionVoter();

    $this->get(route('association.election.admin'))
        ->assertOk()
        ->assertSee('Melde dich mit Nostr an')
        ->assertDontSee('Vorstandswahl Geheimtest');

    NostrAuth::login($member->pubkey);
    $this->get(route('association.election.admin'))->assertForbidden();

    NostrAuth::login($board->pubkey);
    $this->get(route('association.election.admin'))
        ->assertOk()
        ->assertSee('Vorstandswahl Geheimtest')
        ->assertSee('Ablaufplan', false);
});

it('shows the board, and only the board, the entry in the navigation', function () {
    [$board] = electionBoard(1);
    $member = electionVoter();

    NostrAuth::login($member->pubkey);
    $this->get(route('association.profile'))->assertDontSee(route('association.election.admin'));

    NostrAuth::login($board->pubkey);
    $this->get(route('association.profile'))->assertSee(route('association.election.admin'));
});

it('answers 403 on every public method to a member who is not, or no longer, on the board', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $candidate = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);
    $member = electionVoter();

    NostrAuth::login($board->pubkey);
    $methods = electionAdminMethods(electionAdmin()->instance());

    expect($methods)->toContain('save', 'announce', 'approve', 'startDecision', 'confirmDecision', 'enrol', 'deleteElection', 'elections', 'startRoundAction', 'confirmRoundAction', 'sealRound', 'createRunOff', 'saveMeeting', 'purgeTestElection', 'roundReports');

    foreach ($methods as $method) {
        // Loaded by the board, then the session belongs to somebody else:
        // the page must ask again on the request, not trust its mount.
        NostrAuth::login($board->pubkey);
        $page = electionAdmin()->set('decisionReason', 'Grund')->set('enrolReason', 'Grund');
        NostrAuth::login($member->pubkey);

        $reflection = new ReflectionMethod($page->instance(), $method);

        if ($reflection->getAttributes(Computed::class) !== []) {
            unset($page->instance()->{$method});

            expect(fn () => $page->instance()->{$method})->toThrow(HttpException::class);

            continue;
        }

        $arguments = collect($reflection->getParameters())
            ->reject(fn (ReflectionParameter $parameter) => $parameter->getType() instanceof ReflectionNamedType && ! $parameter->getType()->isBuiltin())
            ->map(fn (ReflectionParameter $parameter) => match ($parameter->getName()) {
                'electionId' => $election->id,
                'candidateId' => $candidate->id,
                'roundId' => $round->id,
                'section' => 'roll',
                'index' => 0,
                'kind' => 'reject',
                default => throw new LogicException("No test argument for \${$parameter->getName()} of {$method}()."),
            })
            ->values()
            ->all();

        $page->call($method, ...$arguments)->assertForbidden();
    }

    expect($candidate->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and($election->fresh()->announced_at)->toBeNull()
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
});

it('refuses a guest with 403 on an action', function () {
    electionBoard(1);
    $election = Election::factory()->create();

    electionAdmin()->assertSet('isGuest', true)->call('selectElection', $election->id)->assertForbidden();
});

it('creates an election with the three prepared live rounds and writes the audit line', function () {
    $board = loggedInBoard();

    $page = electionAdmin()
        ->call('startCreate')
        ->assertSet('creating', true)
        ->set('form.name', 'Vorstandswahl 2027')
        ->set('form.meeting_date', '2027-03-20')
        ->set('form.meeting_time', '19:30')
        ->set('form.call_url', 'https://meet.example.org/vorstandswahl')
        ->set('form.is_test', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('creating', false);

    $election = Election::query()->sole();

    expect($election->name)->toBe('Vorstandswahl 2027')
        ->and($election->meeting_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2027-03-20 19:30')
        ->and($election->call_url)->toBe('https://meet.example.org/vorstandswahl')
        ->and($election->is_test)->toBeTrue()
        ->and($election->rounds->pluck('title')->all())->toBe(['Präsidium', 'Vorstand', 'Revisionsstelle'])
        ->and(electionAuditOf($election))->toBe(['election_created'])
        ->and(ElectionAuditEntry::query()->value('actor_pleb_id'))->toBe($board->id);

    $page->assertSet('selectedElectionId', $election->id)->assertSee('Wahl ankündigen');
});

it('refuses an empty name, a single-majority round with more than one seat and a call link that is no URL', function () {
    loggedInBoard();

    electionAdmin()
        ->call('startCreate')
        ->set('form.name', '')
        ->call('save')
        ->assertHasErrors(['form.name']);

    electionAdmin()
        ->call('startCreate')
        ->set('form.name', 'Vorstandswahl 2027')
        ->set('form.rounds.0.seats', 2)
        ->call('save')
        ->assertHasErrors(['form.rounds.0.seats']);

    electionAdmin()
        ->call('startCreate')
        ->set('form.name', 'Vorstandswahl 2027')
        ->set('form.call_url', 'meet.example.org/ohne-schema')
        ->call('save')
        ->assertHasErrors(['form.call_url'])
        ->assertSee('Der Link zum Video-Call muss eine vollständige Adresse sein');

    expect(Election::query()->count())->toBe(0);
});

it('edits a draft election and refuses after a round has been opened', function () {
    loggedInBoard();
    $election = Election::factory()->create(['name' => 'Alt']);
    $round = draftElectionRound(['title' => 'Präsidium'], candidates: 1, election: $election);

    electionAdmin()
        ->set('form.name', 'Neu')
        ->set('form.rounds.0.counting_rule', ElectionCountingRule::YesNoPerCandidate->value)
        ->call('save')
        ->assertHasNoErrors();

    expect($election->fresh()->name)->toBe('Neu')
        ->and($round->fresh()->counting_rule)->toBe(ElectionCountingRule::YesNoPerCandidate)
        ->and(electionAuditOf($election))->toBe(['election_updated']);

    $page = electionAdmin();
    app(OpenRound::class)->handle($round->fresh());

    $page->set('form.name', 'Zu spät')->call('save')->assertHasErrors(['form']);

    expect($election->fresh()->name)->toBe('Neu');
});

it('announces the selected election after the confirm and writes the audit line', function () {
    $board = loggedInBoard();
    $election = Election::factory()->create(['meeting_at' => now()->addMonth()]);
    draftElectionRound(candidates: 0, election: $election);

    electionAdmin()
        ->assertSee('Nächster Schritt:')
        ->assertSee('Wahl ankündigen.')
        ->call('announce')
        ->assertSee('Kandidatur');

    expect($election->fresh()->announced_at)->not->toBeNull()
        ->and(electionAuditOf($election))->toBe(['election_announced'])
        ->and(ElectionAuditEntry::query()->value('actor_pleb_id'))->toBe($board->id);
});

it('deletes a draft election', function () {
    loggedInBoard();
    $election = Election::factory()->create();
    draftElectionRound(candidates: 1, election: $election);

    electionAdmin()->call('deleteElection');

    expect(Election::query()->count())->toBe(0)
        ->and(electionAuditOf($election))->toBe(['election_deleted']);
});

it('offers no delete for an announced election and refuses it on the wire', function () {
    loggedInBoard();
    $election = Election::factory()->announced()->create();
    draftElectionRound(candidates: 1, election: $election);

    electionAdmin()
        ->assertDontSeeHtml('data-test="delete-election"')
        ->call('deleteElection')
        ->assertForbidden();

    expect(Election::query()->count())->toBe(1);
});

it('answers a stale tab with a reload hint, not a 403, when a candidacy is already decided', function (string $action) {
    loggedInBoard();
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $decided = ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => electionVoter()->id]);

    $page = electionAdmin();

    if ($action === 'approve') {
        $page->call('approve', $decided->id)->assertOk();
    } else {
        $page->call('startDecision', $decided->id, $action)
            ->set('decisionReason', 'Schon von jemand anderem entschieden')
            ->call('confirmDecision')
            ->assertOk()
            ->assertHasErrors(['decisionReason'])
            ->assertSee('Lade die Seite neu');
    }

    expect($decided->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(electionAuditOf($election))->toBe([]);
})->with(['approve', 'reject']);

it('approves an entitled candidacy and refuses one whose fee is missing', function () {
    loggedInBoard();
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $paid = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => electionVoter()->id]);
    $unpaid = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);

    electionAdmin()
        ->assertSet('section', 'candidacies')
        ->assertSee('2 Kandidaturen warten auf Prüfung.')
        ->assertSee('Freigabe erst nach der Zahlung')
        ->call('approve', $paid->id)
        ->call('approve', $unpaid->id);

    expect($paid->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and($unpaid->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and(electionAuditOf($election))->toBe(['candidacy_approved']);
});

it('rejects and withdraws a candidacy with a reason that goes into the audit log', function () {
    loggedInBoard();
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);
    $approved = ElectionCandidate::factory()->create(['election_round_id' => $round->id]);

    electionAdmin()
        ->call('startDecision', $pending->id, 'reject')
        ->call('confirmDecision')
        ->assertHasErrors(['decisionReason'])
        ->set('decisionReason', 'Kandidiert für ein Amt, das nicht zur Wahl steht')
        ->call('confirmDecision')
        ->assertHasNoErrors()
        ->call('startDecision', $approved->id, 'withdraw')
        ->set('decisionReason', 'Auf eigenen Wunsch')
        ->call('confirmDecision');

    expect($pending->fresh()->status)->toBe(ElectionCandidateStatus::Rejected)
        ->and($approved->fresh()->status)->toBe(ElectionCandidateStatus::Withdrawn)
        ->and(electionAuditOf($election))->toBe(['candidacy_rejected', 'candidacy_withdrawn'])
        ->and(ElectionAuditEntry::query()->orderBy('sequence')->get()->map(fn ($entry) => $entry->details['reason'])->all())
        ->toBe(['Kandidiert für ein Amt, das nicht zur Wahl steht', 'Auf eigenen Wunsch']);
});

it('shows each entitled member with the reason and who signed in since the announcement', function () {
    // 23:30 UTC is already the next day in the display timezone.
    $this->travelTo(now()->setTime(23, 30));
    loggedInBoard();
    $election = Election::factory()->create(['announced_at' => now()->subDays(3)]);
    draftElectionRound(candidates: 0, election: $election);
    electionVoter()->forceFill(['last_login_at' => now()->subDay()])->save();
    electionVoter()->forceFill(['last_login_at' => now()->subWeek()])->save();
    EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::HONORARY]);

    electionAdmin()
        ->call('showSection', 'roll')
        ->assertSee('3 von 4 Aktiv- und Ehrenmitgliedern sind wahlberechtigt.')
        ->assertSee('Davon hat sich eine Person seit der Ankündigung angemeldet.')
        ->assertSee('Aktivmitglied, Beitrag bezahlt')
        ->assertSee('Ja, '.now()->subDay()->setTimezone(config('app.display_timezone'))->format('d.m.Y'))
        ->assertSee('Beitrag '.now()->year.' fehlt');
});

it('enrols a member by hand in an open round with a reason', function () {
    $board = loggedInBoard();
    $election = Election::factory()->announced()->create();
    $round = openElectionRound(election: $election);
    $member = EinundzwanzigPleb::factory()->active()->create();

    electionAdmin()
        ->call('showSection', 'roll')
        ->assertSee('Mitglied von Hand nachtragen')
        ->set('enrolMemberId', $member->id)
        ->call('enrol')
        ->assertHasErrors(['enrolReason'])
        ->set('enrolReason', 'Beitrag bar bezahlt')
        ->call('enrol')
        ->assertHasNoErrors();

    $entry = ElectionRollEntry::query()->where('election_round_id', $round->id)->where('einundzwanzig_pleb_id', $member->id)->sole();

    expect($entry->reason)->toBe(ElectionRollReason::ConfirmedByBoard)
        ->and($entry->source)->toBe(ElectionRollSource::ByHand)
        ->and(electionAuditOf($election))->toBe(['voter_enrolled_by_hand'])
        ->and(ElectionAuditEntry::query()->sole()->only(['actor_pleb_id', 'subject_pleb_id']))->toBe(['actor_pleb_id' => $board->id, 'subject_pleb_id' => $member->id]);
});

it('keeps the page working after a round trip', function () {
    loggedInBoard();
    $election = Election::factory()->create();
    draftElectionRound(candidates: 0, election: $election);

    electionAdmin()->call('$refresh')->assertOk()
        ->call('showSection', 'candidacies')->assertOk()
        ->call('showSection', 'roll')->assertOk()
        ->call('showSection', 'settings')->assertOk();
});

it('records the moment of a Nostr sign-in on the member', function () {
    $challenge = NostrAuth::issueChallenge();
    ['event' => $event, 'pubkey' => $pubkey] = makeSignedLoginEvent($challenge);

    NostrAuth::loginWithSignedEvent($event);

    expect(EinundzwanzigPleb::query()->where('pubkey', $pubkey)->value('last_login_at'))->not->toBeNull();
});

/*
 * Cash payer on the page: the approval refusal points to the roll, the roll
 * tab enters the entitlement with a reason, and then the approval works.
 */
it('enters a cash payer as entitled from the roll tab, after which the candidacy can be approved', function () {
    $board = loggedInBoard();
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(['title' => 'Kasse'], candidates: 0, election: $election);
    $cashPayer = EinundzwanzigPleb::factory()->active()->create();
    Profile::factory()->create(['pubkey' => $cashPayer->pubkey, 'name' => 'Erik Barzahler']);
    $candidacy = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $cashPayer->id]);

    $page = electionAdmin()
        ->call('approve', $candidacy->id)
        ->call('showSection', 'roll')
        ->assertSeeHtml('data-test="entitle-form"')
        ->call('entitle')
        ->assertHasErrors(['entitleMemberId', 'entitleReason'])
        ->set('entitleMemberId', $cashPayer->id)
        ->set('entitleReason', 'Beitrag bar bezahlt am 3. März')
        ->call('entitle')
        ->assertHasNoErrors();

    expect(app(ElectoralRoll::class)->reasonFor($election, $cashPayer, now()))->toBe(ElectionRollReason::ConfirmedByBoard)
        ->and(electionAuditOf($election))->toBe(['voter_enrolled_by_hand']);

    $page->call('approve', $candidacy->id)
        ->call('entitle')
        ->set('entitleMemberId', $cashPayer->id)->set('entitleReason', 'Noch einmal')->call('entitle')
        ->assertHasErrors(['entitleMemberId'])
        ->assertSee('Erik Barzahler ist schon wahlberechtigt.');

    expect($candidacy->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(electionAuditOf($election))->toBe(['voter_enrolled_by_hand', 'candidacy_approved'])
        ->and(ElectionAuditEntry::query()->where('action', 'voter_enrolled_by_hand')->sole()->actor_pleb_id)->toBe($board->id);
});

it('points a refused approval to the manual entitlement in the roll', function () {
    loggedInBoard();
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(['title' => 'Kasse'], candidates: 0, election: $election);
    $candidacy = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);

    electionAdmin()->call('approve', $candidacy->id)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && str_contains($params['slots']['text'], 'tragt die Wahlberechtigung unter „Wählerverzeichnis“ von Hand ein.'));

    expect($candidacy->fresh()->status)->toBe(ElectionCandidateStatus::Pending);
});
