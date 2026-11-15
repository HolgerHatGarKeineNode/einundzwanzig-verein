<?php

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionCandidateStatus;
use App\Enums\ElectionCountingRule;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionActionRefusedException;
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

/*
 * Mutation that must turn this red: drop the `authorizeActor()` call from
 * `ElectionStewardship::repeat()`.
 */
it('repeats an annulled round once, for the board at the moment of the act only, and writes nothing when refused', function () {
    $board = electionBoard(2);
    $draft = draftElectionRound(['title' => 'Vorsitz', 'counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 3);
    $draft->candidates()->orderBy('id')->first()->forceFill(['status' => ElectionCandidateStatus::Withdrawn])->save();
    $round = stewardship()->annul(stewardship()->open($draft, $board[0]), $board[0], 'Falsche Liste');
    $rounds = ElectionRound::query()->count();

    config(['einundzwanzig.config.current_board' => [$board[1]->npub]]);
    Board::flush();

    expect(fn () => stewardship()->repeat($round, $board[0]))->toThrow(AuthorizationException::class)
        ->and(fn () => stewardship()->repeat($round, electionVoter()))->toThrow(AuthorizationException::class)
        ->and(ElectionRound::query()->count())->toBe($rounds)
        ->and(auditActionsOf($round->election))->toBe(['round_opened', 'round_annulled']);

    $repeat = stewardship()->repeat($round, $board[1]);

    expect($repeat->stateAt(now()))->toBe(ElectionRoundState::Draft)
        ->and($repeat->title)->toBe('Vorsitz (Wiederholung)')
        ->and($repeat->position)->toBe($round->position + 1)
        ->and($repeat->parent_round_id)->toBeNull()
        ->and($repeat->counting_rule)->toBe(ElectionCountingRule::MostVotes)
        ->and($repeat->seats)->toBe(2)
        ->and($repeat->candidates()->where('status', ElectionCandidateStatus::Approved)->pluck('einundzwanzig_pleb_id')->sort()->values()->all())
        ->toBe($round->candidates()->where('status', ElectionCandidateStatus::Approved)->pluck('einundzwanzig_pleb_id')->sort()->values()->all())
        ->and($repeat->candidates()->count())->toBe(2)
        ->and(auditActionsOf($round->election))->toBe(['round_opened', 'round_annulled', 'round_repeated'])
        ->and(ElectionAuditEntry::query()->where('action', ElectionAuditAction::RoundRepeated)->sole()->only('election_round_id', 'actor_pleb_id'))
        ->toBe(['election_round_id' => $round->id, 'actor_pleb_id' => $board[1]->id])
        ->and(fn () => stewardship()->repeat($round, $board[1]))->toThrow(ElectionActionRefusedException::class)
        ->and(fn () => stewardship()->repeat($repeat, $board[1]))->toThrow(ElectionActionRefusedException::class)
        ->and(ElectionRound::query()->count())->toBe($rounds + 1);

    // A repeat that is annulled in turn is repeated under the same title.
    $again = stewardship()->repeat(stewardship()->annul(stewardship()->open($repeat, $board[1]), $board[1], 'Wieder falsch'), $board[1]);

    expect($again->title)->toBe('Vorsitz (Wiederholung)');
});

it('leaves an annulled run-off to a new run-off instead of a repeat', function () {
    $boardMember = electionBoard(1)[0];
    $parent = stewardship()->open(draftElectionRound(['counting_rule' => ElectionCountingRule::MostVotes]), $boardMember);
    stewardship()->closeEarly($parent, $boardMember, 'Fertig');
    $runOff = stewardship()->annul(stewardship()->open(draftElectionRound(['parent_round_id' => $parent->id], election: $parent->election), $boardMember), $boardMember, 'Falsch');

    expect(stewardship()->awaitsRepeat($runOff))->toBeFalse()
        ->and(fn () => stewardship()->repeat($runOff, $boardMember))->toThrow(ElectionActionRefusedException::class);
});

it('keeps a candidacy decision and the opening apart from the rows of the ballot', function () {
    $boardMember = electionBoard(1)[0];
    $round = draftElectionRound();
    $pending = ElectionCandidate::factory()->pending()->create(['election_round_id' => $round->id]);

    app(Candidacies::class)->withdraw($pending, $boardMember, 'Grund');
    stewardship()->open($round, $boardMember);

    expect($pending->fresh()->status)->toBe(ElectionCandidateStatus::Withdrawn)
        ->and(electionVoteCount($round))->toBe(0);
});

/*
 * Board F1: a round nobody can stand in any more must not hold the election
 * open for ever. Struck, it is void like an annulled round, never opened and
 * never awaiting a repeat. Mutations that must turn this red: let `strike()`
 * accept an opened round; let `awaitsRepeat()` count a struck round.
 */
it('strikes a round that was never opened, with the reason in the audit log, and it awaits no repeat', function () {
    [$board] = electionBoard(1);
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(['title' => 'Beisitz'], election: $election);
    $stewardship = app(ElectionStewardship::class);

    $struck = $stewardship->strike($round, $board, 'Niemand kann mehr antreten');
    $line = ElectionAuditEntry::query()->sole();

    expect($struck->stateAt(now()))->toBe(ElectionRoundState::Annulled)
        ->and($struck->opened_at)->toBeNull()
        ->and($line->action)->toBe(ElectionAuditAction::RoundAnnulled)
        ->and([$line->actor_pleb_id, $line->election_round_id])->toBe([$board->id, $round->id])
        ->and($line->details)->toBe(['reason' => 'Niemand kann mehr antreten'])
        ->and($stewardship->awaitsRepeat($struck))->toBeFalse()
        ->and(fn () => $stewardship->repeat($struck, $board))->toThrow(ElectionActionRefusedException::class);
});

it('refuses to strike an opened round, a struck one and a run-off, and writes nothing', function () {
    [$board] = electionBoard(1);
    $stewardship = app(ElectionStewardship::class);
    $opened = openElectionRound();
    $runOff = draftElectionRound(['parent_round_id' => $opened->id], election: $opened->election);
    $struck = $stewardship->strike(draftElectionRound(), $board, 'Niemand mehr da');
    $lines = ElectionAuditEntry::query()->count();

    foreach ([$opened, $runOff, $struck] as $round) {
        expect(fn () => $stewardship->strike($round, $board, 'Noch einmal'))->toThrow(ElectionRoundStateException::class);
    }

    expect($opened->fresh()->annulled_at)->toBeNull()
        ->and($runOff->fresh()->annulled_at)->toBeNull()
        ->and(ElectionAuditEntry::query()->count())->toBe($lines);
});

it('lets only the board strike a round', function () {
    electionBoard(1);
    $round = draftElectionRound();

    expect(fn () => app(ElectionStewardship::class)->strike($round, electionVoter(), 'Grund'))->toThrow(AuthorizationException::class)
        ->and($round->fresh()->annulled_at)->toBeNull();
});
