<?php

use App\Enums\AssociationStatus;
use App\Enums\ElectionBallotRefusal;
use App\Enums\ElectionCountingRule;
use App\Exceptions\ElectionBallotRefusedException;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionReceipt;
use App\Models\ElectionRound;
use App\Services\Election\CastBallot;
use App\Services\Election\CloseRound;
use App\Services\Election\OpenRound;
use App\Services\MembershipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/helpers.php';

/*
 * Taking in a ballot: who may, when, how often — and what must not happen
 * around it.
 */

it('takes in a ballot as one mark, one row per choice and one receipt hash', function () {
    $voter = electionVoter();
    $round = openElectionRound(['counting_rule' => ElectionCountingRule::MostVotes, 'seats' => 2], candidates: 3);
    [$first, $second] = $round->candidates()->pluck('id')->all();

    $code = castElectionBallot($round, $voter, [$first => 'for', $second => 'for']);

    expect(electionRowCounts($round))->toBe(['marks' => 1, 'choices' => 2, 'receipts' => 1])
        ->and(DB::table('election_ballot_choices')->orderBy('election_candidate_id')->pluck('choice', 'election_candidate_id')->all())
        ->toBe([$first => 'for', $second => 'for'])
        ->and(DB::table('election_receipts')->value('hash'))->toBe(ElectionReceipt::hashFor($round->id, $code));
});

it('returns nothing to the caller', function () {
    expect((string) (new ReflectionMethod(CastBallot::class, 'handle'))->getReturnType())->toBe('void');
});

it('accepts an empty ballot and stores it as one blank row', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    castElectionBallot($round, $voter, []);

    expect(electionRowCounts($round))->toBe(['marks' => 1, 'choices' => 1, 'receipts' => 1])
        ->and((array) DB::table('election_ballot_choices')->sole(['election_candidate_id', 'choice']))
        ->toBe(['election_candidate_id' => null, 'choice' => 'blank']);
});

it('refuses a second ballot from the same member and keeps one mark and one ballot', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $candidate = $round->candidates()->value('id');

    castElectionBallot($round, $voter, [$candidate => 'for']);

    expect(electionRefusal(fn () => castElectionBallot($round, $voter, [$candidate => 'for'])))
        ->toBe(ElectionBallotRefusal::AlreadyVoted)
        ->and(electionRowCounts($round))->toBe(['marks' => 1, 'choices' => 1, 'receipts' => 1]);
});

it('lets every member on the roll vote once', function () {
    $voters = collect([electionVoter(), electionVoter(), electionVoter()]);
    $round = openElectionRound();

    $voters->each(fn (EinundzwanzigPleb $voter) => castElectionBallot($round, $voter));

    expect(electionRowCounts($round))->toBe(['marks' => 3, 'choices' => 3, 'receipts' => 3]);
});

/**
 * Rounds that must refuse a ballot, by name. A function rather than a dataset
 * of closures, so each round is built inside the test, after the voter.
 */
function electionRoundThatIsNotOpen(string $kind): ElectionRound
{
    $alter = function (array $columns): ElectionRound {
        $round = openElectionRound();
        DB::table('election_rounds')->where('id', $round->id)->update($columns);

        return $round;
    };

    return match ($kind) {
        'a draft' => draftElectionRound(),
        'a window that has not begun' => app(OpenRound::class)->handle(
            ElectionRound::factory()->withCandidates()->window(now()->addDay(), now()->addDays(2))->create()
        ),
        'a window that has run out' => tap(app(OpenRound::class)->handle(
            ElectionRound::factory()->withCandidates()->window(now(), now()->addHour())->create()
        ), fn () => test()->travelTo(now()->addHour())),
        'a live round past its maximum duration' => tap(
            openElectionRound(),
            fn () => test()->travelTo(now()->addMinutes(OpenRound::LIVE_MAX_MINUTES)),
        ),
        'a sealed round' => app(CloseRound::class)->handle(openElectionRound()),
        'a round without an end' => $alter(['closes_at' => null]),
        'a round without a beginning' => $alter(['opens_at' => null]),
        'an annulled round' => $alter(['annulled_at' => now()]),
        'a round that no longer exists' => tap(
            draftElectionRound(),
            fn (ElectionRound $round) => DB::table('election_rounds')->where('id', $round->id)->delete(),
        ),
    };
}

it('refuses a ballot for a round that is not open', function (string $kind) {
    $voter = electionVoter();
    $round = electionRoundThatIsNotOpen($kind);

    expect(electionRefusal(fn () => castElectionBallot($round, $voter)))
        ->toBe(ElectionBallotRefusal::RoundNotOpen)
        ->and(electionRowCounts($round))->toBe(['marks' => 0, 'choices' => 0, 'receipts' => 0]);
})->with([
    'a draft',
    'a window that has not begun',
    'a window that has run out',
    'a live round past its maximum duration',
    'a sealed round',
    'a round without an end',
    'a round without a beginning',
    'an annulled round',
    'a round that no longer exists',
]);

it('decides on the stored round, not on the instance the caller holds', function () {
    $voter = electionVoter();
    $round = openElectionRound();
    $loadedWhileOpen = ElectionRound::query()->findOrFail($round->id);

    app(CloseRound::class)->handle($round);

    expect(electionRefusal(fn () => castElectionBallot($loadedWhileOpen, $voter)))
        ->toBe(ElectionBallotRefusal::RoundNotOpen);
});

it('refuses a member who is not on the roll', function (string $kind) {
    $year = (int) now()->year;

    $member = match ($kind) {
        'a passive member who has paid' => EinundzwanzigPleb::factory()
            ->withPaidYear($year)
            ->create(['association_status' => AssociationStatus::PASSIVE]),
        'an active member who has not paid' => EinundzwanzigPleb::factory()->active()->create(),
        'an active member who paid for another year' => electionVoter($year - 1),
        'a non-member who has paid' => EinundzwanzigPleb::factory()->withPaidYear($year)->create(),
    };
    $round = openElectionRound();

    expect(electionRefusal(fn () => castElectionBallot($round, $member)))
        ->toBe(ElectionBallotRefusal::NotOnRoll)
        ->and(electionRowCounts($round))->toBe(['marks' => 0, 'choices' => 0, 'receipts' => 0]);
})->with([
    'a passive member who has paid',
    'an active member who has not paid',
    'an active member who paid for another year',
    'a non-member who has paid',
]);

it('refuses a member who became entitled only after the round opened, without a payment', function () {
    $round = openElectionRound();
    $latecomer = electionVoter();

    expect(electionRefusal(fn () => castElectionBallot($round, $latecomer)))->toBe(ElectionBallotRefusal::NotOnRoll);
});

it('keeps the right to vote across a change of year', function () {
    $this->travelTo(Carbon::parse('2026-12-30 12:00:00'));

    $voter = electionVoter(2026);
    $election = Election::factory()->create(['fee_year' => 2026]);
    $round = app(OpenRound::class)->handle(
        ElectionRound::factory()
            ->withCandidates()
            ->window(now(), Carbon::parse('2027-01-05 12:00:00'))
            ->create(['election_event_id' => $election->id])
    );

    $this->travelTo(Carbon::parse('2027-01-02 12:00:00'));

    expect($voter->fresh()->hasPaidMembership())->toBeFalse();

    castElectionBallot($round, $voter);

    expect(electionRowCounts($round)['marks'])->toBe(1);
});

it('keeps the right to vote when the payment is reversed while the round is open', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    $reversal = app(MembershipService::class)->reversePayment($voter->paymentEvents()->sole(), 'invalid', 'test');

    expect($reversal['reversed'])->toBeTrue()
        ->and($voter->fresh()->hasPaidMembership())->toBeFalse();

    castElectionBallot($round, $voter);

    expect(electionRowCounts($round)['marks'])->toBe(1);
});

it('keeps the right to vote when the member is made passive while the round is open', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    $voter->association_status = AssociationStatus::PASSIVE;
    $voter->save();

    castElectionBallot($round, $voter);

    expect(electionRowCounts($round)['marks'])->toBe(1);
});

it('refuses a ballot that does not fit the frozen rule and writes no mark', function (string $kind) {
    $voter = electionVoter();
    $round = openElectionRound();
    $own = $round->candidates()->pluck('id');

    $selection = match ($kind) {
        'more votes than the rule allows' => $own->mapWithKeys(fn (int $id): array => [$id => 'for'])->all(),
        'a candidate of another round' => [draftElectionRound()->candidates()->value('id') => 'for'],
        'a choice the rule does not know' => [$own->first() => 'yes'],
    };

    expect(electionRefusal(fn () => castElectionBallot($round, $voter, $selection)))
        ->toBe(ElectionBallotRefusal::InvalidChoices)
        ->and(electionRowCounts($round))->toBe(['marks' => 0, 'choices' => 0, 'receipts' => 0]);

    castElectionBallot($round, $voter);

    expect(electionRowCounts($round)['marks'])->toBe(1);
})->with([
    'more votes than the rule allows',
    'a candidate of another round',
    'a choice the rule does not know',
]);

it('refuses a receipt code that is not 128 bits of lowercase hex', function (string $code) {
    $voter = electionVoter();
    $round = openElectionRound();

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $voter, [], $code)))
        ->toBe(ElectionBallotRefusal::InvalidReceipt)
        ->and(electionRowCounts($round))->toBe(['marks' => 0, 'choices' => 0, 'receipts' => 0]);
})->with(['empty' => [''], 'too short' => ['abc123'], 'uppercase' => [str_repeat('AB', 16)]]);

it('rolls the whole ballot back when the receipt code is already in use', function () {
    [$first, $second] = [electionVoter(), electionVoter()];
    $round = openElectionRound();
    $code = electionReceiptCode();

    app(CastBallot::class)->handle($round, $first, [], $code);

    expect(electionRefusal(fn () => app(CastBallot::class)->handle($round, $second, [], $code)))
        ->toBe(ElectionBallotRefusal::InvalidReceipt)
        ->and(electionRowCounts($round))->toBe(['marks' => 1, 'choices' => 1, 'receipts' => 1]);

    castElectionBallot($round, $second);

    expect(electionRowCounts($round))->toBe(['marks' => 2, 'choices' => 2, 'receipts' => 2]);
});

it('pushes no job and sends no notification when a ballot is cast', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    Queue::fake();
    Notification::fake();

    castElectionBallot($round, $voter, [$round->candidates()->value('id') => 'for']);

    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

it('dispatches no event of its own when a ballot is cast', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    Event::fake();

    castElectionBallot($round, $voter, [$round->candidates()->value('id') => 'for']);

    /*
     * With the dispatcher faked, the framework's own events are still
     * recorded: Eloquent's lifecycle events for the models that were READ,
     * and the database's transaction and query events. Anything beyond those
     * would be something `CastBallot` dispatched — and no Eloquent event may
     * concern a mark, a ballot row, a receipt or the member. A class being
     * booted is not a row event: it fires once per process and carries the
     * class name only.
     */
    $dispatched = array_keys((new ReflectionProperty(Event::getFacadeRoot(), 'events'))->getValue(Event::getFacadeRoot()));

    $own = array_values(array_filter($dispatched, fn (string $event): bool => ! str_starts_with($event, 'eloquent.')
        && ! str_starts_with($event, 'Illuminate\\Database\\Events\\')));

    $aboutSecretRows = array_values(array_filter($dispatched, fn (string $event): bool => str_starts_with($event, 'eloquent.')
        && preg_match('/^eloquent\.boot(ing|ed): /', $event) !== 1
        && preg_match('/ElectionMark|ElectionBallotChoice|ElectionReceipt|EinundzwanzigPleb/', $event) === 1));

    expect($own)->toBe([])
        ->and($aboutSecretRows)->toBe([])
        ->and($dispatched)->not->toBe([]);
});

it('writes no log line when a ballot is cast or refused', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    $logged = [];
    Log::listen(function ($message) use (&$logged): void {
        $logged[] = $message->message;
    });

    castElectionBallot($round, $voter);
    electionRefusal(fn () => castElectionBallot($round, $voter));

    Log::info('positive control');

    expect($logged)->toBe(['positive control']);
});

it('names neither the member nor the round nor the choice when it refuses', function () {
    $voter = electionVoter();
    $round = openElectionRound();

    castElectionBallot($round, $voter);

    try {
        castElectionBallot($round, $voter, [$round->candidates()->value('id') => 'for']);
    } catch (ElectionBallotRefusedException $exception) {
        expect($exception->getMessage())->toBe('Ballot refused: already_voted')
            ->and($exception->getPrevious())->toBeNull()
            ->and(get_object_vars($exception))->toBe(['reason' => ElectionBallotRefusal::AlreadyVoted]);

        return;
    }

    $this->fail('The second ballot was not refused.');
});

it('tells the member why a ballot was refused, in German under any locale', function () {
    app()->setLocale('en');

    expect(ElectionBallotRefusal::AlreadyVoted->label())->toBe('Du hast in diesem Wahlgang bereits gewählt.')
        ->and(collect(ElectionBallotRefusal::cases())->map->label()->unique())->toHaveCount(count(ElectionBallotRefusal::cases()));
});

it('does not report a refused ballot, nor a refusal of a subclass', function () {
    Exceptions::fake();

    $round = openElectionRound();
    $stranger = electionVoter();

    try {
        castElectionBallot($round, $stranger);
    } catch (ElectionBallotRefusedException $refusal) {
        report($refusal);
    }

    expect($refusal ?? null)->toBeInstanceOf(ElectionBallotRefusedException::class);

    report(new class(ElectionBallotRefusal::AlreadyVoted) extends ElectionBallotRefusedException {});

    Exceptions::assertNothingReported();

    // The fake does record what is reportable — the assertion above can fail.
    report(new RuntimeException('reportable'));

    Exceptions::assertReported(RuntimeException::class);
    expect(Exceptions::reported())->toHaveCount(1);
});

/**
 * Every argument of every frame of `$exception`'s trace as text: scalars and
 * arrays as they are, closures as the variables they use, any other object as
 * its class name.
 */
function electionTraceArguments(Throwable $exception): string
{
    $flatten = function (mixed $value) use (&$flatten): mixed {
        return match (true) {
            $value instanceof Closure => array_map($flatten, (new ReflectionFunction($value))->getClosureUsedVariables()),
            is_object($value) => $value::class,
            is_array($value) => array_map($flatten, $value),
            default => $value,
        };
    };

    return print_r(array_map(fn (array $frame): mixed => $flatten($frame['args'] ?? []), $exception->getTrace()), true);
}

it('shows the selection and the receipt code in a stack trace only as SensitiveParameterValue', function () {
    /*
     * Mutation B1-S1 (patch in the plan folder): drop `#[\SensitiveParameter]`
     * from `$selection` and `$receiptCode` → red. B1-S2: hand the raw values
     * to the transaction closure → red.
     */
    $voter = electionVoter();
    $round = openElectionRound();
    $selection = [$round->candidates()->value('id') => 'for'];
    $code = electionReceiptCode();
    app(CloseRound::class)->handle($round);

    // CLI defaults to dropping arguments from traces; a production server may not.
    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        app(CastBallot::class)->handle($round, $voter, $selection, $code);
    } catch (ElectionBallotRefusedException $refusal) {
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }

    expect($refusal ?? null)->toBeInstanceOf(ElectionBallotRefusedException::class);

    $frame = collect($refusal->getTrace())
        ->first(fn (array $frame): bool => ($frame['class'] ?? null) === CastBallot::class && $frame['function'] === 'handle');

    // The first two arguments are there as they are: the trace does carry arguments.
    expect($frame['args'][0])->toBeInstanceOf(ElectionRound::class)
        ->and($frame['args'][1])->toBeInstanceOf(EinundzwanzigPleb::class)
        ->and($frame['args'][2])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and($frame['args'][3])->toBeInstanceOf(SensitiveParameterValue::class)
        ->and(electionTraceArguments($refusal))->toContain('[ballot] => SensitiveParameterValue')
        ->not->toContain($code);
});
