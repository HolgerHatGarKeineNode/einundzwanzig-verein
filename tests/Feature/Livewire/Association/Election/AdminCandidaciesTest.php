<?php

use App\Enums\ElectionCandidateStatus;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Support\NostrAuth;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';
require_once __DIR__.'/helpers.php';

/*
 * The candidacies of an election on the board's page, a child component of
 * its own: board only, a candidacy of another election is not found, and
 * every decision goes through Candidacies with its audit line. The page
 * follows a decision through the `election-changed` event.
 */

function electionCandidacies(Election $election): Testable
{
    return Livewire::test('association.election.admin-candidacies', ['electionId' => $election->id]);
}

/**
 * @return list<string>
 */
function candidaciesAudit(Election $election): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('action')->map->value->all();
}

it('answers 403 on every public method to a member who is not, or no longer, on the board', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $candidate = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => electionVoter()->id]);
    $member = electionVoter();

    $methods = expectEveryMethodBoardOnly(
        fn () => electionCandidacies($election)->set('decisionReason', 'Grund'),
        $board,
        $member,
        ['candidateId' => $candidate->id, 'kind' => 'reject'],
    );

    expect($methods)->toBe(['approve', 'candidaciesByRound', 'confirmDecision', 'election', 'startDecision'])
        ->and($candidate->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and(ElectionAuditEntry::query()->count())->toBe(0);

    NostrAuth::login($member->pubkey);
    electionCandidacies($election)->assertForbidden();
});

it('does not find a candidacy of another election', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $election = Election::factory()->announced()->create();
    draftElectionRound(candidates: 0, election: $election);
    $other = Election::factory()->announced()->create();
    $foreign = ElectionCandidate::factory()->pending()->create(['election_round_id' => draftElectionRound(candidates: 0, election: $other)->id, 'einundzwanzig_pleb_id' => electionVoter()->id]);

    electionCandidacies($election)->call('approve', $foreign->id)->assertNotFound();
    electionCandidacies($election)->call('startDecision', $foreign->id, 'reject')->assertNotFound();

    expect($foreign->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
});

it('answers a stale tab with a reload hint, not a 403, when a candidacy is already decided', function (string $action) {
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $decided = ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => electionVoter()->id]);

    $candidacies = electionCandidacies($election);

    if ($action === 'approve') {
        $candidacies->call('approve', $decided->id)->assertOk();
    } else {
        $candidacies->call('startDecision', $decided->id, $action)
            ->set('decisionReason', 'Schon von jemand anderem entschieden')
            ->call('confirmDecision')
            ->assertOk()
            ->assertHasErrors(['decisionReason'])
            ->assertSee('Lade die Seite neu');
    }

    expect($decided->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and(candidaciesAudit($election))->toBe([]);
})->with(['approve', 'reject']);

it('approves an entitled candidacy, refuses one whose fee is missing and points to the roll', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $paid = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => electionVoter()->id]);
    $unpaid = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);

    Livewire::test('association.election.admin')
        ->assertSet('section', 'candidacies')
        ->assertSee('2 Kandidaturen warten auf Prüfung.');

    electionCandidacies($election)
        ->assertSee('Freigabe erst nach der Zahlung')
        ->call('approve', $paid->id)
        ->assertDispatched('election-changed')
        ->call('approve', $unpaid->id)
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['dataset']['variant'] ?? null) === 'danger'
            && str_contains($params['slots']['text'], 'tragt die Wahlberechtigung unter „Wählerverzeichnis“ von Hand ein.'));

    expect($paid->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and($unpaid->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and(candidaciesAudit($election))->toBe(['candidacy_approved']);
});

it('rejects and withdraws a candidacy with a reason that goes into the audit log', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);
    $approved = ElectionCandidate::factory()->create(['election_round_id' => $round->id]);

    electionCandidacies($election)
        ->call('startDecision', $pending->id, 'reject')
        ->call('confirmDecision')
        ->assertHasErrors(['decisionReason'])
        ->set('decisionReason', 'Kandidiert für ein Amt, das nicht zur Wahl steht')
        ->call('confirmDecision')
        ->assertHasNoErrors()
        ->call('startDecision', $approved->id, 'withdraw')
        ->assertSee('zurückziehen?')
        ->set('decisionReason', 'Auf eigenen Wunsch')
        ->call('confirmDecision')
        ->assertSet('decisionCandidateId', null);

    expect($pending->fresh()->status)->toBe(ElectionCandidateStatus::Rejected)
        ->and($approved->fresh()->status)->toBe(ElectionCandidateStatus::Withdrawn)
        ->and(candidaciesAudit($election))->toBe(['candidacy_rejected', 'candidacy_withdrawn'])
        ->and(ElectionAuditEntry::query()->orderBy('sequence')->get()->map(fn ($entry) => $entry->details['reason'])->all())
        ->toBe(['Kandidiert für ein Amt, das nicht zur Wahl steht', 'Auf eigenen Wunsch']);
});

it('hands a decision to the page, whose run sheet and tab count follow', function () {
    NostrAuth::login(electionBoard(1)[0]->pubkey);
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 0, election: $election);
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => electionVoter()->id]);

    $page = Livewire::test('association.election.admin')->assertSee('Eine Kandidatur wartet auf Prüfung.')->assertSee('1 offen');

    electionCandidacies($election)->call('approve', $pending->id)->assertDispatched('election-changed');

    $page->dispatch('election-changed')
        ->assertDontSee('Eine Kandidatur wartet auf Prüfung.')
        ->assertDontSee('1 offen');
});
