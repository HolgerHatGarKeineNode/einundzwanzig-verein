<?php

use App\Auth\NostrUser;
use App\Enums\AssociationStatus;
use App\Enums\ElectionCandidateStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Election\OpenRound;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Services/Election/helpers.php';

/*
 * Rights per action in an election (E7). The roles: board, the Wahlleitung
 * of the election (here always a member outside the board), an ordinary
 * member, a guest. Nobody acts in a round they stand in.
 */

function electionGate(?EinundzwanzigPleb $pleb): GateContract
{
    return Gate::forUser($pleb === null ? null : new NostrUser($pleb->pubkey));
}

/**
 * A draft round of an election led by `$lead`, with one board member standing
 * in it and one pending candidacy waiting for a decision.
 *
 * @return array{board: list<EinundzwanzigPleb>, lead: EinundzwanzigPleb, member: EinundzwanzigPleb, round: ElectionRound, pending: ElectionCandidate}
 */
function electionRightsScene(): array
{
    $board = electionBoard(2);
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));

    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $board[1]->id]);
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);

    return ['board' => $board, 'lead' => $lead, 'member' => electionVoter(), 'round' => $round, 'pending' => $pending];
}

dataset('lead-only round abilities', ['closeEarly', 'annul', 'enrolByHand', 'recordPaperVote']);

dataset('steward round abilities', ['open', 'close', 'confirmPaperVote']);

dataset('lead-only candidacy abilities', ['rejectCandidacy', 'withdrawCandidacy']);

it('gives the exceptions in a round to the Wahlleitung alone', function (string $ability) {
    ['board' => $board, 'lead' => $lead, 'member' => $member, 'round' => $round] = electionRightsScene();

    expect(electionGate($lead)->allows($ability, $round))->toBeTrue()
        ->and(electionGate($board[0])->allows($ability, $round))->toBeFalse()
        ->and(electionGate($member)->allows($ability, $round))->toBeFalse()
        ->and(electionGate(null)->allows($ability, $round))->toBeFalse();
})->with('lead-only round abilities');

/*
 * Mutation that must turn this red (DoD 452): drop the `standsIn()` check
 * from `ElectionPolicy::isUninvolvedLead()`, the check `closeEarly` relies on.
 */
it('refuses a Wahlleitung who stands in the round', function (string $ability) {
    ['lead' => $lead, 'round' => $round] = electionRightsScene();

    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $lead->id]);

    expect(electionGate($lead)->allows($ability, $round))->toBeFalse();
})->with('lead-only round abilities');

it('lets the Wahlleitung and board members who do not stand open and seal a round', function (string $ability) {
    ['board' => $board, 'lead' => $lead, 'member' => $member, 'round' => $round] = electionRightsScene();

    expect(electionGate($lead)->allows($ability, $round))->toBeTrue()
        ->and(electionGate($board[0])->allows($ability, $round))->toBeTrue()
        ->and(electionGate($board[1])->allows($ability, $round))->toBeFalse()
        ->and(electionGate($member)->allows($ability, $round))->toBeFalse()
        ->and(electionGate(null)->allows($ability, $round))->toBeFalse();
})->with('steward round abilities');

it('lets a board member who does not stand for this office approve a candidacy, and the Wahlleitung', function () {
    ['board' => $board, 'lead' => $lead, 'member' => $member, 'pending' => $pending] = electionRightsScene();

    expect(electionGate($board[0])->allows('approveCandidacy', $pending))->toBeTrue()
        ->and(electionGate($lead)->allows('approveCandidacy', $pending))->toBeTrue()
        ->and(electionGate($board[1])->allows('approveCandidacy', $pending))->toBeFalse()
        ->and(electionGate($member)->allows('approveCandidacy', $pending))->toBeFalse()
        ->and(electionGate($pending->pleb)->allows('approveCandidacy', $pending))->toBeFalse();
});

it('lets a board member standing in another round approve here', function () {
    ['board' => $board, 'round' => $round, 'pending' => $pending] = electionRightsScene();

    $otherRound = draftElectionRound(election: $round->election);
    ElectionCandidate::factory()->create(['election_round_id' => $otherRound->id, 'einundzwanzig_pleb_id' => $board[0]->id]);

    expect(electionGate($board[0])->allows('approveCandidacy', $pending))->toBeTrue();
});

it('counts a rejected or withdrawn candidacy as standing in the round', function (ElectionCandidateStatus $status) {
    ['board' => $board, 'round' => $round, 'pending' => $pending] = electionRightsScene();

    ElectionCandidate::factory()->status($status)->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $board[0]->id]);

    expect(electionGate($board[0])->allows('approveCandidacy', $pending))->toBeFalse()
        ->and(electionGate($board[0])->allows('open', $round))->toBeFalse();
})->with([ElectionCandidateStatus::Rejected, ElectionCandidateStatus::Withdrawn]);

it('gives rejecting and withdrawing a candidacy to the Wahlleitung alone', function (string $ability) {
    ['board' => $board, 'lead' => $lead, 'member' => $member, 'pending' => $pending] = electionRightsScene();

    expect(electionGate($lead)->allows($ability, $pending))->toBeTrue()
        ->and(electionGate($board[0])->allows($ability, $pending))->toBeFalse()
        ->and(electionGate($member)->allows($ability, $pending))->toBeFalse();
})->with('lead-only candidacy abilities');

it('decides only on candidacies that are still open to a decision', function () {
    ['lead' => $lead, 'round' => $round] = electionRightsScene();

    $rejected = ElectionCandidate::factory()->status(ElectionCandidateStatus::Rejected)->create(['election_round_id' => $round->id]);
    $approved = ElectionCandidate::factory()->create(['election_round_id' => $round->id]);

    expect(electionGate($lead)->allows('approveCandidacy', $rejected))->toBeFalse()
        ->and(electionGate($lead)->allows('withdrawCandidacy', $rejected))->toBeFalse()
        ->and(electionGate($lead)->allows('approveCandidacy', $approved))->toBeFalse()
        ->and(electionGate($lead)->allows('rejectCandidacy', $approved))->toBeFalse()
        ->and(electionGate($lead)->allows('withdrawCandidacy', $approved))->toBeTrue();
});

it('decides on no candidacy once the round is opened', function (string $ability) {
    ['board' => $board, 'lead' => $lead, 'round' => $round, 'pending' => $pending] = electionRightsScene();

    app(OpenRound::class)->handle($round);

    expect(electionGate($lead)->allows($ability, $pending->fresh()))->toBeFalse()
        ->and(electionGate($board[0])->allows($ability, $pending->fresh()))->toBeFalse();
})->with(['approveCandidacy', 'rejectCandidacy', 'withdrawCandidacy']);

it('lets nobody use the Wahlleitung rights of an election without one', function (string $ability) {
    $board = electionBoard(1);
    $round = draftElectionRound();

    expect(electionGate($board[0])->allows($ability, $round))->toBeFalse();
})->with('lead-only round abilities');

it('refuses an erased Wahlleitung', function () {
    ['lead' => $lead, 'round' => $round] = electionRightsScene();

    $lead->forceFill(['pubkey' => EinundzwanzigPleb::TOMBSTONE_PREFIX.bin2hex(random_bytes(20))])->save();

    expect(electionGate($lead->fresh())->allows('closeEarly', $round))->toBeFalse();
});

it('lets a member stand for themself, active or honorary, once and only in a draft round', function () {
    $round = draftElectionRound();
    $member = electionVoter();
    $honorary = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::HONORARY]);
    $passive = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);

    expect(electionGate($member)->allows('submitCandidacy', $round))->toBeTrue()
        ->and(electionGate($honorary)->allows('submitCandidacy', $round))->toBeTrue()
        ->and(electionGate($passive)->allows('submitCandidacy', $round))->toBeFalse()
        ->and(electionGate(null)->allows('submitCandidacy', $round))->toBeFalse();

    ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $member->id]);

    expect(electionGate($member)->allows('submitCandidacy', $round))->toBeFalse();

    app(OpenRound::class)->handle($round);

    expect(electionGate($honorary)->allows('submitCandidacy', $round->fresh()))->toBeFalse();
});

it('deletes an election only while all of its rounds are drafts', function () {
    ['board' => $board, 'lead' => $lead, 'member' => $member, 'round' => $round] = electionRightsScene();
    $election = $round->election;

    expect(electionGate($board[0])->allows('delete', $election))->toBeTrue()
        ->and(electionGate($lead)->allows('delete', $election))->toBeTrue()
        ->and(electionGate($member)->allows('delete', $election))->toBeFalse();

    app(OpenRound::class)->handle($round);

    expect(electionGate($board[0])->allows('delete', $election))->toBeFalse()
        ->and(electionGate($lead)->allows('delete', $election))->toBeFalse();
});

it('announces an election only once and only if it has a Wahlleitung', function () {
    $board = electionBoard(1);
    $withoutLead = Election::factory()->create();
    $led = ledElection();

    expect(electionGate($board[0])->allows('announce', $withoutLead))->toBeFalse()
        ->and(electionGate($board[0])->allows('announce', $led))->toBeTrue()
        ->and(electionGate($board[0])->allows('announce', Election::factory()->ledBy($led->lead)->announced()->create()))->toBeFalse();
});

it('lets the board name the Wahlleitung until a round is opened', function () {
    ['board' => $board, 'lead' => $lead, 'round' => $round] = electionRightsScene();

    expect(electionGate($board[0])->allows('appointLead', $round->election))->toBeTrue()
        ->and(electionGate($lead)->allows('appointLead', $round->election))->toBeFalse();

    app(OpenRound::class)->handle($round);

    expect(electionGate($board[0])->allows('appointLead', $round->election->fresh()))->toBeFalse();
});

/*
 * Mutation that must turn this red: let `view()` ignore `is_test` — every
 * member then sees the trial election.
 */
it('shows a test election to the board and its Wahlleitung only', function () {
    $board = electionBoard(1);
    $lead = electionVoter();
    $member = electionVoter();
    $test = Election::factory()->test()->ledBy($lead)->create();
    $real = Election::factory()->ledBy($lead)->create();

    expect(electionGate($board[0])->allows('view', $test))->toBeTrue()
        ->and(electionGate($lead)->allows('view', $test))->toBeTrue()
        ->and(electionGate($member)->allows('view', $test))->toBeFalse()
        ->and(electionGate(null)->allows('view', $test))->toBeFalse()
        ->and(electionGate($member)->allows('view', $real))->toBeTrue()
        ->and(electionGate(null)->allows('view', $real))->toBeFalse();
});
