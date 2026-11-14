<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Exceptions\ElectionActionRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRollEntry;
use App\Models\ElectionRound;
use App\Models\PaymentEvent;
use App\Services\Election\ChangeMemberStatus;
use App\Services\Election\CloseRound;
use App\Services\Election\ElectionAuditLog;
use App\Services\MembershipService;
use App\Support\NostrAuth;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

/*
 * The status lock (E5) and what follows from a change under it (R2-F1):
 * from the announcement on only the Wahlleitung changes a category that
 * touches the vote, each change leaves an audit line, and a promoted member
 * who then pays inside the window is enrolled under a source of its own.
 */

/**
 * Settle a fee the way the webhook does — through `markPaid()`, which
 * enrols a late payer.
 */
function settleFeeDuringElection(EinundzwanzigPleb $pleb): PaymentEvent
{
    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => 'invoice-'.fake()->uuid(),
    ]);

    $result = app(MembershipService::class)->markPaid($paymentEvent, [
        'id' => $paymentEvent->btc_pay_invoice,
        'status' => 'Settled',
        'amount' => '21000',
        'currency' => 'SATS',
    ]);

    expect($result['settled'])->toBeTrue();

    return $paymentEvent;
}

function announcedElection(EinundzwanzigPleb $lead): Election
{
    return Election::factory()->ledBy($lead)->announced()->create();
}

function rollSourceOf(ElectionRound $round, EinundzwanzigPleb $pleb): ?string
{
    return ElectionRollEntry::query()
        ->where('election_round_id', $round->id)
        ->where('einundzwanzig_pleb_id', $pleb->id)
        ->first()?->source->value;
}

/*
 * R9b. Mutation that must turn this red: let `enrolLatePayer()` always
 * write `late_payment` — the entry then hides the decision behind it.
 */
it('enrols a passive member promoted by the Wahlleitung after the opening who then pays in the window, as status_change (R9b)', function () {
    $lead = electionVoter();
    $member = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);
    $round = openElectionRound(election: announcedElection($lead));

    expect(rollSourceOf($round, $member))->toBeNull();

    app(ChangeMemberStatus::class)->handle($member, AssociationStatus::ACTIVE, $lead);

    // The change alone enrols nobody.
    expect(rollSourceOf($round, $member))->toBeNull();

    settleFeeDuringElection($member);

    $lines = ElectionAuditEntry::query()->where('election_event_id', $round->election_event_id)->orderBy('sequence')->get();

    expect(rollSourceOf($round, $member))->toBe('status_change')
        ->and($lines->pluck('action')->all())->toBe([ElectionAuditAction::MemberStatusChanged, ElectionAuditAction::VoterEnrolledAfterStatusChange])
        ->and($lines[0]->actor_pleb_id)->toBe($lead->id)
        ->and($lines[0]->details)->toBe(['from' => 'PASSIVE', 'to' => 'ACTIVE'])
        ->and([$lines[1]->actor_pleb_id, $lines[1]->subject_pleb_id, $lines[1]->election_round_id])->toBe([null, $member->id, $round->id])
        ->and(app(ElectionAuditLog::class)->verify($round->election))->toBeTrue()
        ->and(electionRefusal(fn () => castElectionBallot($round, $member)))->toBeNull();
});

/*
 * R9c. Mutation that must turn this red: drop the per-election authorization
 * loop in `ChangeMemberStatus::handle()`.
 */
it('refuses a promotion during an announced election by anyone but its Wahlleitung (R9c)', function (string $who) {
    $board = electionBoard(1);
    $lead = electionVoter();
    $member = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);
    $round = openElectionRound(election: announcedElection($lead));

    $actor = match ($who) {
        'a board member' => $board[0],
        'a member' => electionVoter(),
        'nobody' => null,
    };

    expect(fn () => app(ChangeMemberStatus::class)->handle($member, AssociationStatus::ACTIVE, $actor))->toThrow(AuthorizationException::class)
        ->and($member->fresh()->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and(ElectionAuditEntry::query()->count())->toBe(0);

    settleFeeDuringElection($member);

    expect(rollSourceOf($round, $member))->toBeNull();
})->with(['a board member', 'a member', 'nobody']);

it('refuses a Wahlleitung who stands in the election, and one changing its own category', function () {
    $lead = electionVoter();
    $election = announcedElection($lead);
    $round = draftElectionRound(election: $election);
    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $lead->id]);
    $member = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);

    expect(fn () => app(ChangeMemberStatus::class)->handle($member, AssociationStatus::ACTIVE, $lead))->toThrow(AuthorizationException::class);

    $otherLead = electionVoter();
    announcedElection($otherLead);
    $election->delete();

    expect(fn () => app(ChangeMemberStatus::class)->handle($otherLead, AssociationStatus::HONORARY, $otherLead))->toThrow(ElectionActionRefusedException::class)
        ->and($otherLead->fresh()->association_status)->toBe(AssociationStatus::ACTIVE);
});

it('needs the Wahlleitung of every election that holds the lock and writes a line into each', function () {
    $leadA = electionVoter();
    $leadB = electionVoter();
    $electionA = announcedElection($leadA);
    $electionB = announcedElection($leadB);
    $member = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);

    expect(fn () => app(ChangeMemberStatus::class)->handle($member, AssociationStatus::ACTIVE, $leadA))->toThrow(AuthorizationException::class);

    $electionB->forceFill(['election_lead_pleb_id' => $leadA->id])->save();
    app(ChangeMemberStatus::class)->handle($member, AssociationStatus::ACTIVE, $leadA);

    expect(ElectionAuditEntry::query()->where('action', ElectionAuditAction::MemberStatusChanged)->pluck('election_event_id')->sort()->values()->all())
        ->toBe([$electionA->id, $electionB->id]);
});

it('enrols a member promoted between announcement and opening at the opening, as status_change', function () {
    $lead = electionVoter();
    $member = EinundzwanzigPleb::factory()->withPaidYear((int) now()->year)->create(['association_status' => AssociationStatus::PASSIVE]);
    $voter = electionVoter();
    $election = announcedElection($lead);

    app(ChangeMemberStatus::class)->handle($member, AssociationStatus::ACTIVE, $lead);
    $round = openElectionRound(election: $election);

    expect(rollSourceOf($round, $member))->toBe('status_change')
        ->and(rollSourceOf($round, $voter))->toBe('opening')
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::VoterEnrolledAfterStatusChange)->sole()->subject_pleb_id)->toBe($member->id);
});

it('leaves a change free before the announcement, after the last round, in a test election, and for DEFAULT to PASSIVE', function () {
    $lead = electionVoter();
    $member = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);

    Election::factory()->ledBy($lead)->create();
    Election::factory()->ledBy($lead)->announced()->test()->create();
    $over = announcedElection($lead);
    app(CloseRound::class)->handle(openElectionRound(election: $over));

    app(ChangeMemberStatus::class)->handle($member, AssociationStatus::ACTIVE, null);

    $newcomer = EinundzwanzigPleb::factory()->create();
    announcedElection(electionVoter());
    app(ChangeMemberStatus::class)->handle($newcomer, AssociationStatus::PASSIVE, null);

    expect($member->fresh()->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and($newcomer->fresh()->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::MemberStatusChanged)->count())->toBe(0);
});

/*
 * Mutation that must turn this red: drop the `updating` hook from
 * `EinundzwanzigPleb::booted()` — a direct write then slips past the lock.
 */
it('refuses a direct write of a category under the lock, and lets the payment step through', function () {
    announcedElection(electionVoter());
    $member = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);
    $newcomer = EinundzwanzigPleb::factory()->create();

    $member->association_status = AssociationStatus::ACTIVE;

    expect(fn () => $member->save())->toThrow(LogicException::class)
        ->and($member->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);

    $newcomer->association_status = AssociationStatus::PASSIVE;
    $newcomer->save();

    expect($newcomer->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);
});

it('keeps a pending application when the board screen is refused under the lock', function () {
    $board = electionBoard(1);
    announcedElection(electionVoter());
    $applicant = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);
    $applicant->forceFill(['application_for' => AssociationStatus::ACTIVE->value, 'application_text' => 'Ich will aktiv mitmachen.'])->save();

    NostrAuth::login($board[0]->pubkey);

    Livewire::test('association.members.admin')
        ->call('accept', $applicant->id)
        ->call('acceptPleb')
        ->assertOk()
        ->assertSet('confirmAcceptId', null);

    $fresh = $applicant->fresh();

    expect($fresh->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and($fresh->application_for)->toBe(AssociationStatus::ACTIVE->value)
        ->and($fresh->application_text)->toBe('Ich will aktiv mitmachen.');
});

it('lets the board screen promote when the board member is the Wahlleitung', function () {
    $board = electionBoard(1);
    $election = announcedElection($board[0]);
    $applicant = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);
    $applicant->forceFill(['application_for' => AssociationStatus::ACTIVE->value, 'application_text' => 'Text'])->save();

    NostrAuth::login($board[0]->pubkey);

    Livewire::test('association.members.admin')
        ->call('accept', $applicant->id)
        ->call('acceptPleb')
        ->assertOk();

    expect($applicant->fresh()->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and($applicant->fresh()->application_for)->toBeNull()
        ->and(ElectionAuditEntry::query()->where('election_event_id', $election->id)->sole()->action)->toBe(ElectionAuditAction::MemberStatusChanged);
});
