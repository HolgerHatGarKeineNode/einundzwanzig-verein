<?php

use App\Enums\ElectionCandidateStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Support\NostrAuth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';
require_once __DIR__.'/helpers.php';

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

    $methods = expectEveryMethodBoardOnly(
        fn () => electionAdmin(),
        $board,
        $member,
        ['electionId' => $election->id, 'candidateId' => $candidate->id, 'roundId' => $round->id, 'section' => 'roll', 'kind' => 'reject'],
    );

    // The round actions live in admin-live and are checked in AdminLiveControlTest.
    expect($methods)->toContain('announce', 'electionCreated', 'deleteElection', 'elections', 'purgeTestElection', 'selectElection', 'showSection', 'refreshRounds');

    expect($candidate->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and($election->fresh()->announced_at)->toBeNull()
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
});

it('refuses a guest with 403 on an action', function () {
    electionBoard(1);
    $election = Election::factory()->create();

    electionAdmin()->assertSet('isGuest', true)->call('selectElection', $election->id)->assertForbidden();
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
