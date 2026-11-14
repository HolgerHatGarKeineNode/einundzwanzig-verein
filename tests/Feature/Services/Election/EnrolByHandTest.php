<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Services\Election\CloseRound;
use App\Services\Election\EnrolByHand;
use App\Services\Election\OpenRound;
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/helpers.php';

/*
 * The Wahlleitung enrols a member by hand: with a reason in the audit log,
 * a source of its own on the roll, and only while the round runs.
 */

it('enrols an active member by hand, who can then vote', function () {
    $lead = electionVoter();
    $round = openElectionRound(election: ledElection($lead));
    $member = EinundzwanzigPleb::factory()->active()->create();

    app(EnrolByHand::class)->handle($round, $member, $lead, 'Beitrag bar an der Versammlung bezahlt');

    $entry = ElectionRollEntry::query()->where('election_round_id', $round->id)->where('einundzwanzig_pleb_id', $member->id)->sole();
    $line = ElectionAuditEntry::query()->sole();

    expect([$entry->reason->value, $entry->source->value])->toBe(['confirmed_by_lead', 'by_hand'])
        ->and($line->action)->toBe(ElectionAuditAction::VoterEnrolledByHand)
        ->and([$line->actor_pleb_id, $line->subject_pleb_id, $line->election_round_id])->toBe([$lead->id, $member->id, $round->id])
        ->and($line->details)->toBe(['reason' => 'Beitrag bar an der Versammlung bezahlt'])
        ->and(electionRefusal(fn () => castElectionBallot($round, $member)))->toBeNull();
});

it('refuses a board member, a member and a Wahlleitung who stands in the round', function (string $who) {
    $board = electionBoard(1);
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));
    $member = EinundzwanzigPleb::factory()->active()->create();

    if ($who === 'standing lead') {
        ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $lead->id]);
    }

    $round = app(OpenRound::class)->handle($round);

    $actor = match ($who) {
        'board' => $board[0],
        'member' => electionVoter(),
        'standing lead' => $lead,
    };

    expect(fn () => app(EnrolByHand::class)->handle($round, $member, $actor, 'Grund'))->toThrow(AuthorizationException::class)
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $member->id)->exists())->toBeFalse()
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::VoterEnrolledByHand)->exists())->toBeFalse();
})->with(['board', 'member', 'standing lead']);

/*
 * Mutation that must turn this red: drop the state check in
 * `EnrolByHand::handle()` — a sealed round then takes a new voter.
 */
it('enrols nobody in a round that is a draft, sealed or over', function (string $kind) {
    $lead = electionVoter();
    $election = ledElection($lead);
    $member = EinundzwanzigPleb::factory()->active()->create();

    $round = match ($kind) {
        'a draft' => draftElectionRound(election: $election),
        'a sealed round' => app(CloseRound::class)->handle(openElectionRound(election: $election)),
        'a round whose window has run out' => tap(openElectionRound(election: $election), fn () => test()->travelTo(now()->addHours(2))),
    };

    expect(fn () => app(EnrolByHand::class)->handle($round, $member, $lead, 'Grund'))->toThrow(ElectionRoundStateException::class)
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $member->id)->exists())->toBeFalse()
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
})->with(['a draft', 'a sealed round', 'a round whose window has run out']);

it('enrols no member without a vote and leaves an existing entry as it is', function () {
    $lead = electionVoter();
    $voter = electionVoter();
    $round = openElectionRound(election: ledElection($lead));
    $passive = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);

    expect(fn () => app(EnrolByHand::class)->handle($round, $passive, $lead, 'Grund'))->toThrow(ElectionActionRefusedException::class)
        ->and(fn () => app(EnrolByHand::class)->handle($round, $voter, $lead, 'Grund'))->toThrow(ElectionActionRefusedException::class)
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $voter->id)->sole()->source->value)->toBe('opening')
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
});

it('enrols nobody without a reason', function () {
    $lead = electionVoter();
    $round = openElectionRound(election: ledElection($lead));
    $member = EinundzwanzigPleb::factory()->active()->create();

    expect(fn () => app(EnrolByHand::class)->handle($round, $member, $lead, ''))->toThrow(InvalidArgumentException::class)
        ->and(ElectionRollEntry::query()->where('einundzwanzig_pleb_id', $member->id)->exists())->toBeFalse();
});
