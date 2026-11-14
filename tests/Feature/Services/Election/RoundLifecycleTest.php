<?php

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionRoundMode;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use App\Models\Election;
use App\Models\ElectionBallotChoice;
use App\Models\ElectionCandidate;
use App\Models\ElectionMark;
use App\Models\ElectionReceipt;
use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use App\Services\Election\OpenRound;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;

require_once __DIR__.'/helpers.php';

/*
 * A round from draft to sealed: what opening freezes, what the clock decides
 * on its own, and that closing is one-way and unlinks the ballots from the
 * order they were cast in.
 */

function windowElectionRound(Carbon $opensAt, Carbon $closesAt): ElectionRound
{
    return ElectionRound::factory()->withCandidates()->window($opensAt, $closesAt)->create();
}

it('derives the state of a window round from the clock alone', function () {
    $this->travelTo(Carbon::parse('2027-03-01 09:00:00'));

    $round = windowElectionRound(Carbon::parse('2027-03-02 09:00:00'), Carbon::parse('2027-03-03 09:00:00'));

    expect($round->stateAt(now()))->toBe(ElectionRoundState::Draft);

    $round = app(OpenRound::class)->handle($round);

    expect($round->stateAt(now()))->toBe(ElectionRoundState::Scheduled)
        ->and($round->stateAt(Carbon::parse('2027-03-02 08:59:59')))->toBe(ElectionRoundState::Scheduled)
        ->and($round->stateAt(Carbon::parse('2027-03-02 09:00:00')))->toBe(ElectionRoundState::Open)
        ->and($round->stateAt(Carbon::parse('2027-03-03 08:59:59')))->toBe(ElectionRoundState::Open)
        ->and($round->stateAt(Carbon::parse('2027-03-03 09:00:00')))->toBe(ElectionRoundState::Closed);
});

it('treats an opened round with a missing end or beginning as closed', function (string $column) {
    $round = openElectionRound();

    DB::table('election_rounds')->where('id', $round->id)->update([$column => null]);

    expect($round->fresh()->stateAt(now()))->toBe(ElectionRoundState::Closed);
})->with(['closes_at', 'opens_at']);

it('reports an annulled round as annulled whatever its window says', function () {
    $round = openElectionRound();
    $round->annulled_at = now();
    $round->save();

    expect($round->fresh()->stateAt(now()))->toBe(ElectionRoundState::Annulled)
        ->and($round->fresh()->isSealed())->toBeFalse();
});

it('opens a live round now and gives it a maximum duration', function () {
    $this->travelTo(Carbon::parse('2027-03-20 14:00:00'));

    $round = openElectionRound();

    expect($round->stateAt(now()))->toBe(ElectionRoundState::Open)
        ->and($round->opened_at->toDateTimeString())->toBe('2027-03-20 14:00:00')
        ->and($round->opens_at->toDateTimeString())->toBe('2027-03-20 14:00:00')
        ->and($round->closes_at->toDateTimeString())->toBe('2027-03-20 15:00:00')
        ->and($round->closed_at)->toBeNull();
});

it('refuses to open a round that is not ready', function (string $kind) {
    $round = match ($kind) {
        'no candidates' => draftElectionRound(candidates: 0),
        'no seats' => draftElectionRound(['seats' => 0]),
        'a year rule without a year' => draftElectionRound(election: Election::factory()->create(['fee_year' => null])),
        'a window without an end' => ElectionRound::factory()->withCandidates()->create([
            'mode' => ElectionRoundMode::Window,
            'opens_at' => now(),
        ]),
        'a window that ends before it begins' => windowElectionRound(now()->addDays(2), now()->addDay()),
        'a window that is already over' => windowElectionRound(now()->subDays(2), now()->subDay()),
        'a reveal before the end' => tap(
            windowElectionRound(now(), now()->addDay()),
            fn (ElectionRound $round) => $round->update(['reveal_at' => now()->addHour()]),
        ),
        'a run-off of an unsealed round' => draftElectionRound(['parent_round_id' => openElectionRound()->id]),
    };

    expect(fn () => app(OpenRound::class)->handle($round))->toThrow(ElectionRoundStateException::class);

    expect($round->fresh()->opened_at)->toBeNull()
        ->and($round->rollEntries()->count())->toBe(0);
})->with([
    'no candidates',
    'no seats',
    'a year rule without a year',
    'a window without an end',
    'a window that ends before it begins',
    'a window that is already over',
    'a reveal before the end',
    'a run-off of an unsealed round',
]);

it('refuses to open a round twice', function () {
    $round = openElectionRound();

    expect(fn () => app(OpenRound::class)->handle($round))->toThrow(ElectionRoundStateException::class);
});

it('lets a draft round be changed and deleted', function () {
    $round = draftElectionRound();

    $round->update(['seats' => 3, 'counting_rule' => ElectionCountingRule::MostVotes, 'closes_at' => now()->addDay()]);

    expect($round->fresh()->seats)->toBe(3);

    $round->candidates->each->delete();
    $round->delete();

    expect(ElectionRound::query()->whereKey($round->id)->exists())->toBeFalse();
});

it('freezes the window and the rule once the round is opened', function (string $attribute, mixed $value) {
    $round = openElectionRound();
    $before = (array) DB::table('election_rounds')->where('id', $round->id)->sole();

    $round->{$attribute} = $value;

    expect(fn () => $round->save())->toThrow(ElectionRoundStateException::class);

    expect((array) DB::table('election_rounds')->where('id', $round->id)->sole())->toBe($before);
})->with([
    'closes_at' => ['closes_at', '2030-01-01 00:00:00'],
    'opens_at' => ['opens_at', '2020-01-01 00:00:00'],
    'reveal_at' => ['reveal_at', '2030-01-01 00:00:00'],
    'opened_at' => ['opened_at', null],
    'counting_rule' => ['counting_rule', ElectionCountingRule::MostVotes],
    'seats' => ['seats', 5],
    'mode' => ['mode', ElectionRoundMode::Window],
    'position' => ['position', 99],
]);

it('freezes the window of a window round that has been opened ahead of time', function () {
    $round = app(OpenRound::class)->handle(windowElectionRound(now()->addDay(), now()->addDays(2)));

    expect(fn () => $round->update(['closes_at' => now()->addDays(9)]))->toThrow(ElectionRoundStateException::class)
        ->and(fn () => $round->fresh()->update(['opens_at' => now()]))->toThrow(ElectionRoundStateException::class);
});

it('freezes the candidates once the round is opened', function () {
    $round = openElectionRound();
    $candidate = $round->candidates()->firstOrFail();

    expect(fn () => ElectionCandidate::factory()->create(['election_round_id' => $round->id]))
        ->toThrow(ElectionRoundStateException::class)
        ->and(fn () => $candidate->delete())->toThrow(ElectionRoundStateException::class)
        ->and(fn () => $candidate->update(['election_round_id' => draftElectionRound()->id]))
        ->toThrow(ElectionRoundStateException::class)
        ->and($round->candidates()->count())->toBe(2);
});

it('freezes the fee rule of an election once one of its rounds is opened', function () {
    $round = openElectionRound();
    $election = $round->election;

    expect(fn () => $election->update(['fee_year' => 1999]))->toThrow(ElectionRoundStateException::class)
        ->and(fn () => $election->fresh()->update(['honorary_without_fee' => true]))->toThrow(ElectionRoundStateException::class);

    $election->fresh()->update(['name' => 'Renamed']);

    expect($election->fresh()->name)->toBe('Renamed');
});

it('refuses to delete a round that has been opened', function () {
    $round = openElectionRound();

    expect(fn () => $round->delete())->toThrow(ElectionRoundStateException::class)
        ->and(ElectionRound::query()->whereKey($round->id)->exists())->toBeTrue();
});

it('seals a live round by hand and refuses ballots from then on', function () {
    [$early, $late] = [electionVoter(), electionVoter()];
    $round = openElectionRound();

    castElectionBallot($round, $early);

    $sealed = app(CloseRound::class)->handle($round);

    expect($sealed->stateAt(now()))->toBe(ElectionRoundState::Closed)
        ->and($sealed->isSealed())->toBeTrue()
        ->and(electionRefusal(fn () => castElectionBallot($round, $late)))->not->toBeNull()
        ->and(electionRowCounts($round))->toBe(['marks' => 1, 'choices' => 1, 'receipts' => 1]);
});

it('cannot undo a close', function () {
    $this->travelTo(Carbon::parse('2027-03-20 14:00:00'));

    $round = app(CloseRound::class)->handle(openElectionRound());

    $this->travelTo(Carbon::parse('2027-03-20 14:10:00'));

    expect(fn () => $round->fresh()->forceFill(['closed_at' => null])->save())->toThrow(ElectionRoundStateException::class)
        ->and(fn () => $round->fresh()->forceFill(['closed_at' => now()])->save())->toThrow(ElectionRoundStateException::class)
        ->and(fn () => app(OpenRound::class)->handle($round))->toThrow(ElectionRoundStateException::class);

    $again = app(CloseRound::class)->handle($round);

    expect($again->closed_at->toDateTimeString())->toBe('2027-03-20 14:00:00')
        ->and($round->fresh()->stateAt(now()))->toBe(ElectionRoundState::Closed);
});

it('cannot undo an annulment', function () {
    $round = openElectionRound();
    $round->annulled_at = now();
    $round->save();

    expect(fn () => $round->fresh()->forceFill(['annulled_at' => null])->save())->toThrow(ElectionRoundStateException::class)
        ->and(fn () => app(CloseRound::class)->handle($round))->toThrow(ElectionRoundStateException::class);
});

it('refuses to seal a round that was never opened or has not begun', function () {
    $draft = draftElectionRound();
    $scheduled = app(OpenRound::class)->handle(windowElectionRound(now()->addDay(), now()->addDays(2)));

    expect(fn () => app(CloseRound::class)->handle($draft))->toThrow(ElectionRoundStateException::class)
        ->and(fn () => app(CloseRound::class)->handle($scheduled))->toThrow(ElectionRoundStateException::class)
        ->and($scheduled->fresh()->closed_at)->toBeNull();
});

it('seals a window round only after its window has run out', function () {
    $round = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addDay()));

    expect(fn () => app(CloseRound::class)->handle($round))->toThrow(ElectionRoundStateException::class)
        ->and($round->fresh()->closed_at)->toBeNull();

    $this->travelTo(now()->addDay());

    expect(app(CloseRound::class)->handle($round)->isSealed())->toBeTrue();
});

it('rewrites ballot rows and receipts at the close, keeping what they say', function () {
    $voters = collect(range(1, 6))->map(fn () => electionVoter());
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 3);
    $candidates = $round->candidates()->pluck('id');

    $voters->each(fn ($voter, int $index) => castElectionBallot($round, $voter, match ($index % 3) {
        0 => [$candidates[0] => 'for', $candidates[1] => 'for'],
        1 => [$candidates[2] => 'for'],
        2 => [],
    }));

    $content = fn () => DB::table('election_ballot_choices')
        ->selectRaw('election_candidate_id, choice, count(*) as rows')
        ->groupBy('election_candidate_id', 'choice')
        ->orderBy('election_candidate_id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->all();

    $idsBefore = DB::table('election_ballot_choices')->pluck('id');
    $contentBefore = $content();
    $receiptsBefore = DB::table('election_receipts')->orderBy('hash')->pluck('hash')->all();

    app(CloseRound::class)->handle($round);

    $idsAfter = DB::table('election_ballot_choices')->pluck('id');

    expect($idsAfter)->toHaveCount(8)
        ->and($idsAfter->intersect($idsBefore)->all())->toBe([])
        ->and($idsAfter->every(fn (string $id): bool => preg_match('/^[0-9a-f]{32}$/D', $id) === 1))->toBeTrue()
        ->and($content())->toBe($contentBefore)
        ->and(DB::table('election_receipts')->orderBy('hash')->pluck('hash')->all())->toBe($receiptsBefore)
        ->and(electionRowCounts($round))->toBe(['marks' => 6, 'choices' => 8, 'receipts' => 6]);
});

it('leaves the ballots of other rounds alone when one round is sealed', function () {
    $voter = electionVoter();
    [$sealed, $other] = [openElectionRound(), openElectionRound()];

    castElectionBallot($sealed, $voter);
    castElectionBallot($other, $voter);

    $otherId = DB::table('election_ballot_choices')->where('election_round_id', $other->id)->value('id');

    app(CloseRound::class)->handle($sealed);

    expect(DB::table('election_ballot_choices')->where('election_round_id', $other->id)->value('id'))->toBe($otherId)
        ->and(electionRowCounts($other))->toBe(['marks' => 1, 'choices' => 1, 'receipts' => 1]);
});

it('hands out ballot rows only once the round is sealed', function () {
    $voter = electionVoter();
    $round = app(OpenRound::class)->handle(windowElectionRound(now(), now()->addDay()));

    castElectionBallot($round, $voter);

    expect(fn () => $round->sealedChoices())->toThrow(ElectionRoundStateException::class);

    $this->travelTo(now()->addDay());

    // The window is over, but nothing has been rewritten yet.
    expect($round->fresh()->stateAt(now()))->toBe(ElectionRoundState::Closed)
        ->and(fn () => $round->fresh()->sealedChoices())->toThrow(ElectionRoundStateException::class);

    $staleOpenInstance = $round->fresh();
    $forged = $round->fresh()->forceFill(['closed_at' => now()]);

    expect(fn () => $forged->sealedChoices())->toThrow(ElectionRoundStateException::class);

    app(CloseRound::class)->handle($round);

    expect($staleOpenInstance->sealedChoices()->count())->toBe(1);
});

it('never hands out the ballot rows of an annulled round', function () {
    $round = app(CloseRound::class)->handle(openElectionRound());
    $round->annulled_at = now();
    $round->save();

    expect(fn () => $round->sealedChoices())->toThrow(ElectionRoundStateException::class);
});

it('offers no public method that yields ballot rows or counts before the close', function () {
    $voters = collect([electionVoter(), electionVoter(), electionVoter()]);
    $round = openElectionRound();
    $voters->each(fn ($voter) => castElectionBallot($round, $voter, [$round->candidates()->value('id') => 'for']));

    $secret = [ElectionBallotChoice::class, ElectionMark::class, ElectionReceipt::class];
    $called = [];

    foreach ([$round->fresh(), $round->election] as $model) {
        $methods = collect((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->filter(fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $model::class)
            ->reject(fn (ReflectionMethod $method): bool => $method->isStatic() || $method->getNumberOfRequiredParameters() > 0);

        foreach ($methods as $method) {
            $called[] = $model::class.'::'.$method->getName();

            try {
                $result = $method->invoke($model);
            } catch (ElectionRoundStateException) {
                continue;
            }

            if ($result instanceof Relation || $result instanceof Builder) {
                expect($result->getModel()::class)->not->toBeIn($secret);

                continue;
            }

            // What is left may be a flag, but never a number or a list.
            expect($result)->toBeBool();
        }
    }

    expect($called)->toContain(ElectionRound::class.'::sealedChoices', ElectionRound::class.'::candidates');
});

it('reads ballot rows in the application only where the secrecy is guarded', function () {
    $files = collect(Finder::create()->files()->in([app_path(), resource_path('views'), base_path('routes')])->name('*.php'))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/ElectionBallotChoice|election_ballot_choices/', $file->getContents()) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().'/', '', $file->getPathname()))
        ->sort()
        ->values()
        ->all();

    expect($files)->toBe([
        'app/Models/ElectionBallotChoice.php',
        'app/Models/ElectionRound.php',
        'app/Services/Election/CastBallot.php',
        'app/Services/Election/CloseRound.php',
    ]);
});
