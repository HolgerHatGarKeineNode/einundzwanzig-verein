<?php

use App\Auth\NostrUser;
use App\Enums\AssociationStatus;
use App\Enums\ElectionCandidateStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Election\OpenRound;
use App\Support\Board;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Services/Election/helpers.php';

/*
 * Rights per action in an election (E7). The board is the Wahlleitung: every
 * current board member may take every step, also in a round they stand in.
 * The other roles: an ordinary member, a guest.
 */

function electionGate(?EinundzwanzigPleb $pleb): GateContract
{
    return Gate::forUser($pleb === null ? null : new NostrUser($pleb->pubkey));
}

/**
 * A draft round with one board member standing in it and one pending
 * candidacy waiting for a decision.
 *
 * @return array{board: list<EinundzwanzigPleb>, member: EinundzwanzigPleb, round: ElectionRound, pending: ElectionCandidate}
 */
function electionRightsScene(): array
{
    $board = electionBoard(2);
    $round = draftElectionRound();

    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $board[1]->id]);
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);

    return ['board' => $board, 'member' => electionVoter(), 'round' => $round, 'pending' => $pending];
}

dataset('steward round abilities', ['open', 'close', 'closeEarly', 'annul', 'enrolByHand']);

dataset('steward candidacy abilities', ['approveCandidacy', 'rejectCandidacy', 'withdrawCandidacy']);

/*
 * Mutation that must turn this red: drop the board check from
 * `ElectionPolicy::isBoard()` — a member outside the board can then steer.
 */
it('gives every step in a round to every board member, a candidate among them included', function (string $ability) {
    ['board' => $board, 'member' => $member, 'round' => $round] = electionRightsScene();

    expect(electionGate($board[0])->allows($ability, $round))->toBeTrue()
        ->and(electionGate($board[1])->allows($ability, $round))->toBeTrue()
        ->and(electionGate($member)->allows($ability, $round))->toBeFalse()
        ->and(electionGate(null)->allows($ability, $round))->toBeFalse();
})->with('steward round abilities');

it('gives the decisions on a candidacy to every board member, a candidate among them included', function (string $ability) {
    ['board' => $board, 'member' => $member, 'pending' => $pending] = electionRightsScene();

    expect(electionGate($board[0])->allows($ability, $pending))->toBeTrue()
        ->and(electionGate($board[1])->allows($ability, $pending))->toBeTrue()
        ->and(electionGate($member)->allows($ability, $pending))->toBeFalse()
        ->and(electionGate($pending->pleb)->allows($ability, $pending))->toBeFalse()
        ->and(electionGate(null)->allows($ability, $pending))->toBeFalse();
})->with('steward candidacy abilities');

it('decides only on candidacies that are still open to a decision', function () {
    ['board' => $board, 'round' => $round] = electionRightsScene();

    $rejected = ElectionCandidate::factory()->status(ElectionCandidateStatus::Rejected)->create(['election_round_id' => $round->id]);
    $approved = ElectionCandidate::factory()->create(['election_round_id' => $round->id]);

    expect(electionGate($board[0])->allows('approveCandidacy', $rejected))->toBeFalse()
        ->and(electionGate($board[0])->allows('withdrawCandidacy', $rejected))->toBeFalse()
        ->and(electionGate($board[0])->allows('approveCandidacy', $approved))->toBeFalse()
        ->and(electionGate($board[0])->allows('rejectCandidacy', $approved))->toBeFalse()
        ->and(electionGate($board[0])->allows('withdrawCandidacy', $approved))->toBeTrue();
});

it('decides on no candidacy once the round is opened', function (string $ability) {
    ['board' => $board, 'round' => $round, 'pending' => $pending] = electionRightsScene();

    app(OpenRound::class)->handle($round);

    expect(electionGate($board[0])->allows($ability, $pending->fresh()))->toBeFalse();
})->with('steward candidacy abilities');

it('refuses a member who has left the board', function (string $ability) {
    ['board' => $board, 'round' => $round] = electionRightsScene();

    config(['einundzwanzig.config.current_board' => [$board[1]->npub]]);
    Board::flush();

    expect(electionGate($board[0])->allows($ability, $round))->toBeFalse()
        ->and(electionGate($board[1])->allows($ability, $round))->toBeTrue();
})->with('steward round abilities');

it('refuses an erased board member', function () {
    ['board' => $board, 'round' => $round] = electionRightsScene();

    $board[0]->forceFill(['pubkey' => EinundzwanzigPleb::TOMBSTONE_PREFIX.bin2hex(random_bytes(20))])->save();

    expect(electionGate($board[0]->fresh())->allows('closeEarly', $round))->toBeFalse();
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
    ['board' => $board, 'member' => $member, 'round' => $round] = electionRightsScene();
    $election = $round->election;

    expect(electionGate($board[0])->allows('delete', $election))->toBeTrue()
        ->and(electionGate($board[1])->allows('delete', $election))->toBeTrue()
        ->and(electionGate($member)->allows('delete', $election))->toBeFalse();

    app(OpenRound::class)->handle($round);

    expect(electionGate($board[0])->allows('delete', $election))->toBeFalse();
});

it('lets the board announce an election, once', function () {
    $board = electionBoard(1);
    $member = electionVoter();
    $election = Election::factory()->create();

    expect(electionGate($board[0])->allows('announce', $election))->toBeTrue()
        ->and(electionGate($member)->allows('announce', $election))->toBeFalse()
        ->and(electionGate($board[0])->allows('announce', Election::factory()->announced()->create()))->toBeFalse();
});

/*
 * Mutation that must turn this red: let `view()` ignore `is_test` — every
 * member then sees the trial election.
 */
it('shows a test election to the board only', function () {
    $board = electionBoard(1);
    $member = electionVoter();
    $test = Election::factory()->test()->create();
    $real = Election::factory()->create();

    expect(electionGate($board[0])->allows('view', $test))->toBeTrue()
        ->and(electionGate($member)->allows('view', $test))->toBeFalse()
        ->and(electionGate(null)->allows('view', $test))->toBeFalse()
        ->and(electionGate($member)->allows('view', $real))->toBeTrue()
        ->and(electionGate(null)->allows('view', $real))->toBeFalse();
});
