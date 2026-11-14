<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionAuditAction;
use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCandidateStatus;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Services\Election\Candidacies;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\OpenRound;
use Illuminate\Auth\Access\AuthorizationException;

require_once __DIR__.'/helpers.php';

/*
 * A candidacy from submission to the ballot: submitted by the member,
 * decided with an audit line, frozen once the round is opened, and on the
 * ballot only when approved.
 */

/**
 * The audit actions written for an election, in order.
 *
 * @return list<string>
 */
function electionAuditActions(int $electionId): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $electionId)->orderBy('sequence')->pluck('action')->map->value->all();
}

it('takes a candidacy as pending, with the statement, and writes no audit line', function () {
    $round = draftElectionRound();
    $member = EinundzwanzigPleb::factory()->active()->create();

    $candidate = app(Candidacies::class)->submit($round, $member, 'Ich stelle mich vor.');

    expect($candidate->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and($candidate->statement)->toBe('Ich stelle mich vor.')
        ->and($candidate->einundzwanzig_pleb_id)->toBe($member->id)
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
});

it('refuses a second candidacy of the same member and one by a member without a vote', function () {
    $round = draftElectionRound();
    $member = EinundzwanzigPleb::factory()->active()->create();
    $passive = EinundzwanzigPleb::factory()->create(['association_status' => AssociationStatus::PASSIVE]);

    app(Candidacies::class)->submit($round, $member);

    expect(fn () => app(Candidacies::class)->submit($round, $member))->toThrow(AuthorizationException::class)
        ->and(fn () => app(Candidacies::class)->submit($round, $passive))->toThrow(AuthorizationException::class)
        ->and($round->candidates()->where('status', 'pending')->count())->toBe(1);
});

it('approves a candidacy once the candidate is entitled, with an audit line naming the decider', function () {
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));
    $candidate = app(Candidacies::class)->submit($round, electionVoter());

    app(Candidacies::class)->approve($candidate, $lead);

    $line = ElectionAuditEntry::query()->where('election_event_id', $round->election_event_id)->sole();

    expect($candidate->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and($line->action)->toBe(ElectionAuditAction::CandidacyApproved)
        ->and($line->actor_pleb_id)->toBe($lead->id)
        ->and($line->subject_pleb_id)->toBe($candidate->einundzwanzig_pleb_id)
        ->and($line->details)->toBe(['candidate_id' => $candidate->id]);
});

/*
 * Mutation that must turn this red: drop the entitlement check from
 * `Candidacies::decide()` — an unpaid candidate is then approved.
 */
it('holds the approval back until the candidate has paid', function () {
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));
    $candidate = app(Candidacies::class)->submit($round, EinundzwanzigPleb::factory()->active()->create());

    expect(fn () => app(Candidacies::class)->approve($candidate, $lead))->toThrow(ElectionActionRefusedException::class)
        ->and($candidate->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
});

it('rejects and withdraws with the reason in the audit line', function () {
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));
    $rejected = app(Candidacies::class)->submit($round, electionVoter());
    $withdrawn = ElectionCandidate::factory()->create(['election_round_id' => $round->id]);

    app(Candidacies::class)->reject($rejected, $lead, 'Nicht wählbar nach Art. 7');
    app(Candidacies::class)->withdraw($withdrawn, $lead, 'Rückzug per Mail vom 3. März');

    expect($rejected->fresh()->status)->toBe(ElectionCandidateStatus::Rejected)
        ->and($withdrawn->fresh()->status)->toBe(ElectionCandidateStatus::Withdrawn)
        ->and(electionAuditActions($round->election_event_id))->toBe(['candidacy_rejected', 'candidacy_withdrawn'])
        ->and(ElectionAuditEntry::query()->orderBy('sequence')->get()->pluck('details.reason')->all())
        ->toBe(['Nicht wählbar nach Art. 7', 'Rückzug per Mail vom 3. März'])
        ->and(app(ElectionAuditLog::class)->verify($round->election))->toBeTrue();
});

it('refuses a reject or a withdrawal by a board member and changes nothing', function (string $method) {
    $board = electionBoard(1);
    $round = draftElectionRound(election: ledElection());
    $candidate = app(Candidacies::class)->submit($round, electionVoter());

    expect(fn () => app(Candidacies::class)->{$method}($candidate, $board[0], 'Grund'))->toThrow(AuthorizationException::class)
        ->and($candidate->fresh()->status)->toBe(ElectionCandidateStatus::Pending)
        ->and(ElectionAuditEntry::query()->count())->toBe(0);
})->with(['reject', 'withdraw']);

it('changes no candidacy once the round is opened', function () {
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));
    $approved = $round->candidates()->first();
    $pending = app(Candidacies::class)->submit($round, electionVoter());

    app(OpenRound::class)->handle($round);

    expect(fn () => app(Candidacies::class)->withdraw($approved, $lead, 'zu spät'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(Candidacies::class)->approve($pending, $lead))->toThrow(AuthorizationException::class)
        ->and(fn () => app(Candidacies::class)->submit($round, electionVoter()))->toThrow(AuthorizationException::class)
        ->and(fn () => $approved->fresh()->forceFill(['status' => ElectionCandidateStatus::Withdrawn])->save())->toThrow(ElectionRoundStateException::class)
        ->and($approved->fresh()->status)->toBe(ElectionCandidateStatus::Approved)
        ->and($pending->fresh()->status)->toBe(ElectionCandidateStatus::Pending);
});

it('opens a round only with an approved candidate', function () {
    $round = draftElectionRound(candidates: 0);
    ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);
    ElectionCandidate::factory()->status(ElectionCandidateStatus::Withdrawn)->create(['election_round_id' => $round->id]);

    expect(fn () => app(OpenRound::class)->handle($round))->toThrow(ElectionRoundStateException::class)
        ->and($round->fresh()->opened_at)->toBeNull();
});

/*
 * Mutation that must turn this red: drop the status filter on the candidate
 * list in `CastBallot` — a vote for a pending, rejected or withdrawn
 * candidacy is then taken in.
 */
it('takes no vote for a candidacy that is not approved', function (ElectionCandidateStatus $status) {
    $voter = electionVoter();
    $round = draftElectionRound();
    $other = ElectionCandidate::factory()->status($status)->create(['election_round_id' => $round->id]);
    $round = app(OpenRound::class)->handle($round);

    expect(electionRefusal(fn () => castElectionBallot($round, $voter, [$other->id => 'for'])))->toBe(ElectionBallotRefusal::InvalidChoices)
        ->and(electionRowCounts($round))->toBe(['marks' => 0, 'choices' => 0, 'receipts' => 0])
        ->and(electionRefusal(fn () => castElectionBallot($round, $voter, [$round->candidates()->where('status', 'approved')->value('id') => 'for'])))->toBeNull();
})->with([ElectionCandidateStatus::Pending, ElectionCandidateStatus::Rejected, ElectionCandidateStatus::Withdrawn]);
