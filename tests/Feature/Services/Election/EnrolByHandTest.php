<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Services\Election\Candidacies;
use App\Services\Election\CloseRound;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\EnrolByHand;
use App\Services\Election\OpenRound;
use App\Support\Board;
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/helpers.php';

/*
 * A board member enrols a member by hand: with a reason in the audit log,
 * a source of its own on the roll, and only while the round runs.
 */

it('enrols an active member by hand, who can then vote', function () {
    $boardMember = electionBoard(1)[0];
    $round = openElectionRound();
    $member = EinundzwanzigPleb::factory()->active()->create();

    app(EnrolByHand::class)->handle($round, $member, $boardMember, 'Beitrag bar an der Versammlung bezahlt');

    $entry = ElectionRollEntry::query()->where('election_round_id', $round->id)->where('einundzwanzig_pleb_id', $member->id)->sole();
    $line = ElectionAuditEntry::query()->sole();

    expect([$entry->reason->value, $entry->source->value])->toBe(['confirmed_by_board', 'by_hand'])
        ->and($line->action)->toBe(ElectionAuditAction::VoterEnrolledByHand)
        ->and([$line->actor_pleb_id, $line->subject_pleb_id, $line->election_round_id])->toBe([$boardMember->id, $member->id, $round->id])
        ->and($line->details)->toBe(['reason' => 'Beitrag bar an der Versammlung bezahlt'])
        ->and(electionRefusal(fn () => castElectionBallot($round, $member)))->toBeNull();
});

it('lets a board member who stands in the round enrol by hand', function () {
    $boardMember = electionBoard(1)[0];
    $round = draftElectionRound();
    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $boardMember->id]);
    $round = app(OpenRound::class)->handle($round);
    $member = EinundzwanzigPleb::factory()->active()->create();

    app(EnrolByHand::class)->handle($round, $member, $boardMember, 'Grund');

    expect(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $member->id)->exists())->toBeTrue();
});

it('refuses a member outside the board and one who left it', function (string $who) {
    $board = electionBoard(2);
    $round = openElectionRound();
    $member = EinundzwanzigPleb::factory()->active()->create();

    if ($who === 'left the board') {
        config(['einundzwanzig.config.current_board' => [$board[1]->npub]]);
        Board::flush();
    }

    $actor = $who === 'member' ? electionVoter() : $board[0];

    expect(fn () => app(EnrolByHand::class)->handle($round, $member, $actor, 'Grund'))->toThrow(AuthorizationException::class)
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $member->id)->exists())->toBeFalse()
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::VoterEnrolledByHand)->exists())->toBeFalse();
})->with(['member', 'left the board']);

/*
 * Mutation that must turn this red: drop the state check in
 * `EnrolByHand::handle()` — a sealed round then takes a new voter.
 */
it('enrols nobody in a round that is a draft, sealed or over', function (string $kind) {
    $boardMember = electionBoard(1)[0];
    $election = Election::factory()->create();
    $member = EinundzwanzigPleb::factory()->active()->create();

    $round = match ($kind) {
        'a draft' => draftElectionRound(election: $election),
        'a sealed round' => app(CloseRound::class)->handle(openElectionRound(election: $election)),
        'a round whose window has run out' => tap(openElectionRound(election: $election), fn () => test()->travelTo(now()->addHours(2))),
    };

    expect(fn () => app(EnrolByHand::class)->handle($round, $member, $boardMember, 'Grund'))->toThrow(ElectionRoundStateException::class)
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $member->id)->exists())->toBeFalse()
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
})->with(['a draft', 'a sealed round', 'a round whose window has run out']);

it('enrols no member without a vote and leaves an existing entry as it is', function () {
    $boardMember = electionBoard(1)[0];
    $voter = electionVoter();
    $round = openElectionRound();
    $passive = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);

    expect(fn () => app(EnrolByHand::class)->handle($round, $passive, $boardMember, 'Grund'))->toThrow(ElectionActionRefusedException::class)
        ->and(fn () => app(EnrolByHand::class)->handle($round, $voter, $boardMember, 'Grund'))->toThrow(ElectionActionRefusedException::class)
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $voter->id)->sole()->source->value)->toBe('opening')
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
});

it('enrols nobody without a reason', function () {
    $boardMember = electionBoard(1)[0];
    $round = openElectionRound();
    $member = EinundzwanzigPleb::factory()->active()->create();

    expect(fn () => app(EnrolByHand::class)->handle($round, $member, $boardMember, ''))->toThrow(InvalidArgumentException::class)
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $member->id)->exists())->toBeFalse();
});

/*
 * Cash payer (board F5): the portal does not know the fee, so the board
 * enters the member as entitled before a round opens. The audit line is the
 * entitlement: the candidacy can be approved, and the next opening puts the
 * member on the roll. Mutations that must turn this red: drop the board
 * entitlements from `ElectoralRoll::entitled()`; let `entitle()` skip the
 * category check.
 */
it('entitles a cash payer before a round opens: approvable, then on the roll at the opening', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(['title' => 'Kasse'], candidates: 0, election: $election);
    $cashPayer = EinundzwanzigPleb::factory()->active()->create();
    $candidacy = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $cashPayer->id]);
    $candidacies = app(Candidacies::class);

    expect(fn () => $candidacies->approve($candidacy, $board))->toThrow(ElectionActionRefusedException::class);

    app(EnrolByHand::class)->entitle($election, $cashPayer, $board, 'Beitrag bar bezahlt am 3. März');

    $line = ElectionAuditEntry::query()->where('action', ElectionAuditAction::VoterEnrolledByHand->value)->sole();

    expect([$line->actor_pleb_id, $line->subject_pleb_id, $line->election_round_id])->toBe([$board->id, $cashPayer->id, null])
        ->and($line->details)->toBe(['reason' => 'Beitrag bar bezahlt am 3. März'])
        ->and(app(ElectoralRoll::class)->reasonFor($election, $cashPayer, now()))->toBe(ElectionRollReason::ConfirmedByBoard);

    $candidacies->approve($candidacy, $board);
    app(OpenRound::class)->handle($round->fresh());

    $entry = ElectionRollEntry::query()->where('election_round_id', $round->id)->where('einundzwanzig_pleb_id', $cashPayer->id)->sole();

    expect($candidacy->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and([$entry->reason, $entry->source])->toBe([ElectionRollReason::ConfirmedByBoard, ElectionRollSource::Opening]);
});

it('entitles in its own election only', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $other = Election::factory()->create();
    draftElectionRound(election: $election);
    $cashPayer = EinundzwanzigPleb::factory()->active()->create();

    app(EnrolByHand::class)->entitle($election, $cashPayer, $board, 'Bar bezahlt');

    expect(app(ElectoralRoll::class)->reasonFor($other, $cashPayer, now()))->toBeNull();
});

it('refuses a manual entitlement for a passive member, for one entitled already, and once every round is opened', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->create();
    $round = draftElectionRound(election: $election);
    $enrol = app(EnrolByHand::class);

    expect(fn () => $enrol->entitle($election, EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]), $board, 'Bar'))
        ->toThrow(ElectionActionRefusedException::class, 'Only an active or honorary member can be entitled.')
        ->and(fn () => $enrol->entitle($election, electionVoter(), $board, 'Bar'))
        ->toThrow(ElectionActionRefusedException::class, 'This member is entitled already.');

    app(OpenRound::class)->handle($round);

    expect(fn () => $enrol->entitle($election, EinundzwanzigPleb::factory()->active()->create(), $board, 'Bar'))
        ->toThrow(ElectionRoundStateException::class)
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::VoterEnrolledByHand->value)->count())->toBe(0);
});
