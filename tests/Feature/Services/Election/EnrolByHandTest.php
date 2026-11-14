<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Services\Election\CloseRound;
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
