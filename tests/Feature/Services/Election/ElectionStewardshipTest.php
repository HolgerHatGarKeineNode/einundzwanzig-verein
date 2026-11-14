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

it('lets a board member who stands as candidate approve, open, close and close early the round (DoD 443)', function () {
    $board = electionBoard(2);
    $voter = electionVoter();
    $election = Election::factory()->create();
    $round = draftElectionRound(candidates: 0, election: $election);

    $candidacies = collect($board)->map(fn ($member) => app(Candidacies::class)->submit($round, $member));

    expect(EinundzwanzigPleb::query()->whereIn('pubkey', Board::pubkeys())->pluck('id')->sort()->values()->all())
        ->toBe($round->candidates()->pluck('einundzwanzig_pleb_id')->sort()->values()->all());

    $candidacies->each(fn (ElectionCandidate $candidate) => app(Candidacies::class)->approve($candidate, $board[0]));
    $round = stewardship()->open($round, $board[0]);
    castElectionBallot($round, $voter, [$candidacies[0]->id => 'for']);
    $round = stewardship()->closeEarly($round, $board[1], 'Alle Anwesenden haben gewählt.');

    expect($round->isSealed())->toBeTrue()
        ->and(auditActionsOf($election))->toBe(['candidacy_approved', 'candidacy_approved', 'round_opened', 'round_closed_early'])
        ->and(ElectionAuditEntry::query()->where('election_event_id', $election->id)->orderBy('sequence')->pluck('actor_pleb_id')->all())
        ->toBe([$board[0]->id, $board[0]->id, $board[0]->id, $board[1]->id])
        ->and(app(ElectionAuditLog::class)->verify($election))->toBeTrue();

    $this->travelTo(Carbon::parse('2027-03-01 09:00:00'));
    $window = ElectionRound::factory()->window(now()->addDay(), now()->addDays(2))->create(['election_event_id' => $election->id]);
    ElectionCandidate::factory()->create(['election_round_id' => $window->id, 'einundzwanzig_pleb_id' => electionVoter()->id]);
    $window = stewardship()->open($window, $board[0]);

    $this->travelTo(now()->addDays(3));
    $window = stewardship()->close($window, $board[0]);

    expect($window->isSealed())->toBeTrue()
        ->and(array_slice(auditActionsOf($election), -2))->toBe(['round_opened', 'round_closed']);
});

it('seals a window round after its end, not before, with an audit line', function () {
    $this->travelTo(Carbon::parse('2027-03-01 09:00:00'));
    $board = electionBoard(1);
    $round = ElectionRound::factory()->withCandidates()->window(now()->addDay(), now()->addDays(2))->create();

    $round = stewardship()->open($round, $board[0]);

    expect(fn () => stewardship()->close($round, $board[0]))->toThrow(ElectionRoundStateException::class);

    $this->travelTo(now()->addDays(3));
    $round = stewardship()->close($round, $board[0]);

    expect($round->isSealed())->toBeTrue()
        ->and(auditActionsOf($round->election))->toBe(['round_opened', 'round_closed']);
});

it('refuses to close a live round by the plain close, which is closing it early without a reason', function () {
    $board = electionBoard(1);
    $round = stewardship()->open(draftElectionRound(), $board[0]);

    expect(fn () => stewardship()->close($round, $board[0]))->toThrow(ElectionRoundStateException::class)
        ->and($round->fresh()->stateAt(now()))->toBe(ElectionRoundState::Open)
        ->and(auditActionsOf($round->election))->toBe(['round_opened']);
});

it('annuls an opened round with the reason, once', function () {
    $boardMember = electionBoard(1)[0];
    $round = stewardship()->open(draftElectionRound(), $boardMember);

    $round = stewardship()->annul($round, $boardMember, 'Technischer Fehler beim Stimmzettel');

    expect($round->stateAt(now()))->toBe(ElectionRoundState::Annulled)
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::RoundAnnulled)->sole()->details)->toBe(['reason' => 'Technischer Fehler beim Stimmzettel'])
        ->and(fn () => stewardship()->annul($round, $boardMember, 'nochmal'))->toThrow(ElectionRoundStateException::class);
});

it('refuses an annulment without a reason and leaves the round as it was', function () {
    $boardMember = electionBoard(1)[0];
    $round = stewardship()->open(draftElectionRound(), $boardMember);

    expect(fn () => stewardship()->annul($round, $boardMember, ' '))->toThrow(InvalidArgumentException::class)
        ->and($round->fresh()->annulled_at)->toBeNull();
});

it('deletes a draft election with an audit line that outlives it, and refuses after an opening', function () {
    $board = electionBoard(1);
    $draft = Election::factory()->create();
    draftElectionRound(election: $draft);

    stewardship()->delete($draft, $board[0]);

    expect(Election::query()->whereKey($draft->id)->exists())->toBeFalse()
        ->and(auditActionsOf($draft))->toBe(['election_deleted']);

    $opened = Election::factory()->create();
    stewardship()->open(draftElectionRound(election: $opened), $board[0]);

    expect(fn () => stewardship()->delete($opened, $board[0]))->toThrow(AuthorizationException::class)
        ->and(Election::query()->whereKey($opened->id)->exists())->toBeTrue();
});

it('announces the election once, with an audit line', function () {
    $board = electionBoard(1);

    $election = stewardship()->announce(Election::factory()->create(), $board[0]);

    expect($election->announced_at)->not->toBeNull()
        ->and(auditActionsOf($election))->toBe(['election_announced'])
        ->and(fn () => stewardship()->announce($election, $board[0]))->toThrow(AuthorizationException::class);
});

it('refuses an ordinary member every privileged act and writes nothing', function (string $act) {
    $boardMember = electionBoard(1)[0];
    $member = electionVoter();
    $round = draftElectionRound();

    expect(fn () => match ($act) {
        'open' => stewardship()->open($round, $member),
        'annul' => stewardship()->annul(stewardship()->open($round, $boardMember), $member, 'Grund'),
        'closeEarly' => stewardship()->closeEarly(stewardship()->open($round, $boardMember), $member, 'Grund'),
        'delete' => stewardship()->delete($round->election, $member),
        'announce' => stewardship()->announce($round->election, $member),
    })->toThrow(AuthorizationException::class);

    expect(ElectionAuditEntry::query()->where('actor_pleb_id', $member->id)->count())->toBe(0);
})->with(['open', 'annul', 'closeEarly', 'delete', 'announce']);

/*
 * The page that offers the button was loaded while the member was still on
 * the board; the act asks again.
 *
 * Mutation that must turn this red: drop the `authorizeActor()` call from
 * `ElectionStewardship::open()`.
 */
it('refuses a member who left the board after the page was loaded, at the moment of the act', function (string $act) {
    $board = electionBoard(2);
    $round = draftElectionRound();
    $opened = stewardship()->open(draftElectionRound(), $board[1]);

    config(['einundzwanzig.config.current_board' => [$board[1]->npub]]);
    Board::flush();

    expect(fn () => match ($act) {
        'open' => stewardship()->open($round, $board[0]),
        'closeEarly' => stewardship()->closeEarly($opened, $board[0], 'Grund'),
        'annul' => stewardship()->annul($opened, $board[0], 'Grund'),
        'delete' => stewardship()->delete($round->election, $board[0]),
        'announce' => stewardship()->announce($round->election, $board[0]),
    })->toThrow(AuthorizationException::class)
        ->and($round->fresh()->opened_at)->toBeNull()
        ->and($opened->fresh()->closed_at)->toBeNull()
        ->and(ElectionAuditEntry::query()->where('actor_pleb_id', $board[0]->id)->count())->toBe(0);
})->with(['open', 'closeEarly', 'annul', 'delete', 'announce']);

it('keeps a candidacy decision and the opening apart from the rows of the ballot', function () {
    $boardMember = electionBoard(1)[0];
    $round = draftElectionRound();
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);

    app(Candidacies::class)->withdraw($pending, $boardMember, 'Grund');
    stewardship()->open($round, $boardMember);

    expect($pending->fresh()->status)->toBe(ElectionCandidateStatus::Withdrawn)
        ->and(electionVoteCount($round))->toBe(0);
});
