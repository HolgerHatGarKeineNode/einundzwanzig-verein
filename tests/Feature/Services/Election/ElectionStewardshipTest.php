<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Services\Election\Candidacies;
use App\Services\Election\ElectionAuditLog;
use App\Services\Election\ElectionStewardship;
use App\Support\Board;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

require_once __DIR__.'/helpers.php';

/*
 * The privileged acts on an election and its rounds: each goes through the
 * policy and leaves exactly one audit line, and a refused act leaves none.
 */

function stewardship(): ElectionStewardship
{
    return app(ElectionStewardship::class);
}

/**
 * @return list<string>
 */
function auditActionsOf(Election $election): array
{
    return ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('action')->map->value->all();
}

it('runs an election in which the whole board stands, through a Wahlleitung from outside the board (DoD 443)', function () {
    $board = electionBoard(2);
    $lead = electionVoter();
    $voter = electionVoter();
    $election = ledElection($lead);
    $round = draftElectionRound(candidates: 0, election: $election);

    $candidacies = collect($board)->map(fn ($member) => app(Candidacies::class)->submit($round, $member));

    expect(EinundzwanzigPleb::query()->whereIn('pubkey', Board::pubkeys())->pluck('id')->sort()->values()->all())
        ->toBe($round->candidates()->pluck('einundzwanzig_pleb_id')->sort()->values()->all());

    // No board member can approve or open: each stands in the round.
    expect(fn () => app(Candidacies::class)->approve($candidacies[0], $board[1]))->toThrow(AuthorizationException::class)
        ->and(fn () => stewardship()->open($round, $board[0]))->toThrow(AuthorizationException::class);

    $candidacies->each(fn (ElectionCandidate $candidate) => app(Candidacies::class)->approve($candidate, $lead));
    $round = stewardship()->open($round, $lead);
    castElectionBallot($round, $voter, [$candidacies[0]->id => 'for']);
    $round = stewardship()->closeEarly($round, $lead, 'Alle Anwesenden haben gewählt.');

    expect($round->isSealed())->toBeTrue()
        ->and(auditActionsOf($election))->toBe(['candidacy_approved', 'candidacy_approved', 'round_opened', 'round_closed_early'])
        ->and(ElectionAuditEntry::query()->where('election_event_id', $election->id)->pluck('actor_pleb_id')->unique()->values()->all())->toBe([$lead->id])
        ->and(app(ElectionAuditLog::class)->verify($election))->toBeTrue();
});

it('seals a window round after its end by a board member who does not stand, with an audit line', function () {
    $this->travelTo(Carbon::parse('2027-03-01 09:00:00'));
    $board = electionBoard(1);
    $round = ElectionRound::factory()->withCandidates()->window(now()->addDay(), now()->addDays(2))->create([
        'election_event_id' => ledElection()->id,
    ]);

    $round = stewardship()->open($round, $board[0]);

    expect(fn () => stewardship()->close($round, $board[0]))->toThrow(ElectionRoundStateException::class);

    $this->travelTo(now()->addDays(3));
    $round = stewardship()->close($round, $board[0]);

    expect($round->isSealed())->toBeTrue()
        ->and(auditActionsOf($round->election))->toBe(['round_opened', 'round_closed']);
});

it('refuses to close a live round by the plain close, which would end it early without the Wahlleitung', function () {
    $board = electionBoard(1);
    $round = stewardship()->open(draftElectionRound(election: ledElection()), $board[0]);

    expect(fn () => stewardship()->close($round, $board[0]))->toThrow(ElectionRoundStateException::class)
        ->and($round->fresh()->stateAt(now()))->toBe(ElectionRoundState::Open)
        ->and(auditActionsOf($round->election))->toBe(['round_opened']);
});

it('ends a round early only by its Wahlleitung, and refuses one who stands in it', function () {
    $board = electionBoard(1);
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));
    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $lead->id]);
    $round = stewardship()->open($round, $board[0]);

    expect(fn () => stewardship()->closeEarly($round, $board[0], 'Grund'))->toThrow(AuthorizationException::class)
        ->and(fn () => stewardship()->closeEarly($round, $lead, 'Grund'))->toThrow(AuthorizationException::class)
        ->and($round->fresh()->closed_at)->toBeNull()
        ->and(auditActionsOf($round->election))->toBe(['round_opened']);
});

it('annuls an opened round by its Wahlleitung, with the reason, once', function () {
    $lead = electionVoter();
    $round = stewardship()->open(draftElectionRound(election: ledElection($lead)), $lead);

    $round = stewardship()->annul($round, $lead, 'Technischer Fehler beim Stimmzettel');

    expect($round->stateAt(now()))->toBe(ElectionRoundState::Annulled)
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::RoundAnnulled)->sole()->details)->toBe(['reason' => 'Technischer Fehler beim Stimmzettel'])
        ->and(fn () => stewardship()->annul($round, $lead, 'nochmal'))->toThrow(ElectionRoundStateException::class);
});

it('refuses an annulment without a reason and leaves the round as it was', function () {
    $lead = electionVoter();
    $round = stewardship()->open(draftElectionRound(election: ledElection($lead)), $lead);

    expect(fn () => stewardship()->annul($round, $lead, ' '))->toThrow(InvalidArgumentException::class)
        ->and($round->fresh()->annulled_at)->toBeNull();
});

it('deletes a draft election with an audit line that outlives it, and refuses after an opening', function () {
    $board = electionBoard(1);
    $draft = ledElection();
    draftElectionRound(election: $draft);

    stewardship()->delete($draft, $board[0]);

    expect(Election::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(auditActionsOf($draft))->toBe(['election_deleted']);

    $opened = ledElection();
    stewardship()->open(draftElectionRound(election: $opened), $board[0]);

    expect(fn () => stewardship()->delete($opened, $board[0]))->toThrow(AuthorizationException::class)
        ->and(Election::query()->whereKey($opened->id)->exists())->toBeTrue();
});

it('names the Wahlleitung and announces the election, each with an audit line', function () {
    $board = electionBoard(1);
    $lead = electionVoter();
    $election = Election::factory()->create();

    expect(fn () => stewardship()->announce($election, $board[0]))->toThrow(AuthorizationException::class);

    stewardship()->appointLead($election, $lead, $board[0]);
    $election = stewardship()->announce($election->fresh(), $board[0]);

    expect($election->election_lead_pleb_id)->toBe($lead->id)
        ->and($election->announced_at)->not->toBeNull()
        ->and(auditActionsOf($election))->toBe(['lead_appointed', 'election_announced'])
        ->and(fn () => stewardship()->appointLead($election, $lead, $lead))->toThrow(AuthorizationException::class);
});

it('refuses an ordinary member every privileged act and writes nothing', function (string $act) {
    $member = electionVoter();
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));

    expect(fn () => match ($act) {
        'open' => stewardship()->open($round, $member),
        'annul' => stewardship()->annul(stewardship()->open($round, $lead), $member, 'Grund'),
        'closeEarly' => stewardship()->closeEarly(stewardship()->open($round, $lead), $member, 'Grund'),
        'delete' => stewardship()->delete($round->election, $member),
        'announce' => stewardship()->announce($round->election, $member),
        'appointLead' => stewardship()->appointLead($round->election, $member, $member),
    })->toThrow(AuthorizationException::class);

    expect(ElectionAuditEntry::query()->where('actor_pleb_id', $member->id)->count())->toBe(0);
})->with(['open', 'annul', 'closeEarly', 'delete', 'announce', 'appointLead']);

it('keeps a candidacy decision and the opening apart from the rows of the ballot', function () {
    $lead = electionVoter();
    $round = draftElectionRound(election: ledElection($lead));
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);

    app(Candidacies::class)->withdraw($pending, $lead, 'Grund');
    stewardship()->open($round, $lead);

    expect($pending->fresh()->status)->toBe(ElectionCandidateStatus::Withdrawn)
        ->and(electionRowCounts($round))->toBe(['marks' => 0, 'choices' => 0, 'receipts' => 0]);
});
