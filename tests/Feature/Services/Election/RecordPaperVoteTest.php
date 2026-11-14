<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionBallotRefusal;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use App\Services\Election\OpenRound;
use App\Services\Election\RecordPaperVote;
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/helpers.php';

/*
 * "Stimmt auf Papier": a mark without a ballot row, signed by the
 * Wahlleitung and a second person, after which the member cannot vote
 * online in that round.
 */

/**
 * @return array{board: list<EinundzwanzigPleb>, lead: EinundzwanzigPleb, voter: EinundzwanzigPleb, other: EinundzwanzigPleb, round: ElectionRound}
 */
function paperVoteScene(): array
{
    $board = electionBoard(1);
    $lead = electionVoter();
    $voter = electionVoter();
    $other = electionVoter();

    return ['board' => $board, 'lead' => $lead, 'voter' => $voter, 'other' => $other, 'round' => openElectionRound(election: ledElection($lead))];
}

it('records a paper vote as a mark without a ballot row, with both signers in the audit line', function () {
    ['board' => $board, 'lead' => $lead, 'voter' => $voter, 'round' => $round] = paperVoteScene();

    app(RecordPaperVote::class)->handle($round, $voter, $lead, $board[0]);

    $line = ElectionAuditEntry::query()->sole();

    expect(electionRowCounts($round))->toBe(['marks' => 1, 'choices' => 0, 'receipts' => 0])
        ->and($line->action)->toBe(ElectionAuditAction::PaperVoteRecorded)
        ->and([$line->actor_pleb_id, $line->second_pleb_id, $line->subject_pleb_id])->toBe([$lead->id, $board[0]->id, $voter->id])
        ->and($line->details)->toBeNull();
});

it('blocks the online ballot of a member who voted on paper', function () {
    ['board' => $board, 'lead' => $lead, 'voter' => $voter, 'round' => $round] = paperVoteScene();

    app(RecordPaperVote::class)->handle($round, $voter, $lead, $board[0]);

    expect(electionRefusal(fn () => castElectionBallot($round, $voter)))->toBe(ElectionBallotRefusal::AlreadyVoted)
        ->and(electionRowCounts($round))->toBe(['marks' => 1, 'choices' => 0, 'receipts' => 0]);
});

it('refuses a paper vote of a member who voted online, and a second paper vote', function () {
    ['board' => $board, 'lead' => $lead, 'voter' => $voter, 'other' => $other, 'round' => $round] = paperVoteScene();

    castElectionBallot($round, $voter);
    app(RecordPaperVote::class)->handle($round, $other, $lead, $board[0]);

    expect(fn () => app(RecordPaperVote::class)->handle($round, $voter, $lead, $board[0]))->toThrow(ElectionActionRefusedException::class)
        ->and(fn () => app(RecordPaperVote::class)->handle($round, $other, $lead, $board[0]))->toThrow(ElectionActionRefusedException::class)
        ->and(electionRowCounts($round)['marks'])->toBe(2)
        ->and(ElectionAuditEntry::query()->count())->toBe(1);
});

/*
 * Mutation that must turn this red: drop the distinct-people check in
 * `RecordPaperVote::handle()` — the Wahlleitung then signs twice.
 */
it('refuses a paper vote without a distinct second person', function (string $case) {
    ['lead' => $lead, 'voter' => $voter, 'round' => $round] = paperVoteScene();

    $second = match ($case) {
        'the Wahlleitung itself' => $lead,
        'the voter' => $voter,
    };

    expect(fn () => app(RecordPaperVote::class)->handle($round, $voter, $lead, $second))->toThrow(ElectionActionRefusedException::class)
        ->and(electionRowCounts($round)['marks'])->toBe(0)
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
})->with(['the Wahlleitung itself', 'the voter']);

it('refuses a second person who is no steward or who stands in the round', function (string $case) {
    $board = electionBoard(2);
    $lead = electionVoter();
    $voter = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));
    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $board[1]->id]);
    $round = app(OpenRound::class)->handle($round);

    $second = match ($case) {
        'an ordinary member' => electionVoter(),
        'a standing board member' => $board[1],
    };

    expect(fn () => app(RecordPaperVote::class)->handle($round, $voter, $lead, $second))->toThrow(AuthorizationException::class)
        ->and(electionRowCounts($round)['marks'])->toBe(0);
})->with(['an ordinary member', 'a standing board member']);

it('refuses a board member recording a paper vote, a voter off the roll and a round that is not open', function () {
    ['board' => $board, 'lead' => $lead, 'voter' => $voter, 'round' => $round] = paperVoteScene();
    $offRoll = EinundzwanzigPleb::factory()->active()->create();

    expect(fn () => app(RecordPaperVote::class)->handle($round, $voter, $board[0], $lead))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RecordPaperVote::class)->handle($round, $offRoll, $lead, $board[0]))->toThrow(ElectionActionRefusedException::class);

    $sealed = app(CloseRound::class)->handle($round);

    expect(fn () => app(RecordPaperVote::class)->handle($sealed, $voter, $lead, $board[0]))->toThrow(ElectionRoundStateException::class)
        ->and(electionRowCounts($round)['marks'])->toBe(0)
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
});
