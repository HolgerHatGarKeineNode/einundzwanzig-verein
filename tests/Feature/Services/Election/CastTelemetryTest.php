<?php

use App\Enums\ElectionBallotRefusal;
use App\Exceptions\ElectionBallotWriteException;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionRound;
use App\Models\SecurityAttempt;
use App\Services\Election\CastBallot;
use App\Services\Election\OpenRound;
use App\Services\SecurityMonitor;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\EventHandler as SentryEventHandler;
use Sentry\Laravel\Integration as SentryIntegration;
use Sentry\Laravel\Tracing\EventHandler as SentryTracingHandler;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

require_once __DIR__.'/helpers.php';

/*
 * E23: a cast — taken in, refused or tampered with — leaves neither the
 * choice nor the member's pubkey in Sentry, in `security_attempts` or in an
 * exception message. And casting is throttled per member.
 *
 * The candidate id 7340021 is chosen so it cannot occur by accident.
 */

const TELEMETRY_CANDIDATE_ID = 7340021;

/**
 * A round with one approved candidate under the id 7340021 and a voter on
 * its roll.
 *
 * @return array{0: ElectionRound, 1: EinundzwanzigPleb}
 */
function telemetryRound(): array
{
    $voter = electionVoter();
    $round = draftElectionRound(candidates: 1);
    DB::table('election_candidates')->where('election_round_id', $round->id)->update(['id' => TELEMETRY_CANDIDATE_ID]);

    return [app(OpenRound::class)->handle($round->fresh()), $voter];
}

/**
 * Bind a Sentry client that keeps its events in memory, with SQL
 * breadcrumbs and query spans switched on WITH bindings — the most
 * talkative configuration production could be given.
 *
 * @return ArrayObject<int, Event>
 */
function recordSentryEvents(): ArrayObject
{
    $events = new ArrayObject;

    $transport = new class($events) implements TransportInterface
    {
        public function __construct(private ArrayObject $events) {}

        public function send(Event $event): Result
        {
            $this->events->append($event);

            return new Result(ResultStatus::success(), $event);
        }

        public function close(?int $timeout = null): Result
        {
            return new Result(ResultStatus::success());
        }
    };

    $client = ClientBuilder::create([
        'dsn' => 'https://public@sentry.invalid/1',
        'default_integrations' => false,
        'integrations' => [new SentryIntegration],
        'traces_sample_rate' => 1.0,
    ])->setTransport($transport)->getClient();

    SentrySdk::setCurrentHub(new Hub($client));

    (new SentryEventHandler(app(), ['breadcrumbs' => ['sql_queries' => true, 'sql_bindings' => true]]))->subscribe(app('events'));
    (new SentryTracingHandler(['sql_queries' => true, 'sql_bindings' => true, 'sql_origin' => false]))->subscribe(app('events'));

    return $events;
}

afterEach(function () {
    SentrySdk::setCurrentHub(new Hub);
});

/*
 * Mutation that must turn this red: call `write()` outside the
 * `withScope()` in `CastBallot::handle()` — the writes then leave their SQL
 * breadcrumbs and query spans, bindings included.
 */
it('leaves no breadcrumb and no query span of a cast in Sentry, with bindings switched on', function () {
    [$round, $voter] = telemetryRound();
    $event = signedElectionVote($round, $voter, [TELEMETRY_CANDIDATE_ID => 'for']);
    $events = recordSentryEvents();

    $hub = SentrySdk::getCurrentHub();
    $transaction = $hub->startTransaction(TransactionContext::make()->setName('cast')->setSampled(true));
    $hub->setSpan($transaction);

    app(CastBallot::class)->handle($round, $voter, $event);

    // Positive control: a query outside the cast is recorded, binding and all.
    DB::table('einundzwanzig_plebs')->where('id', 9876543)->count();

    $hub->captureMessage('after the cast');
    $transaction->finish();

    $message = collect($events)->first(fn (Event $event) => $event->getMessage() === 'after the cast');
    $trace = collect($events)->first(fn (Event $event) => $event->getSpans() !== []);

    $recorded = json_encode([
        array_map(fn ($crumb) => [$crumb->getMessage(), $crumb->getMetadata()], $message->getBreadcrumbs()),
        array_map(fn ($span) => [$span->getDescription(), $span->getData()], $trace->getSpans()),
    ]);

    expect($recorded)->toContain('9876543')
        ->not->toContain((string) TELEMETRY_CANDIDATE_ID)
        ->not->toContain($event['sig'])
        ->not->toContain($voter->pubkey)
        ->not->toContain('election_votes')
        ->and(electionVoteCount($round))->toBe(1);
});

/*
 * Mutation that must turn this red: store the payload for election
 * components too (drop the `$election ?` in front of `sanitizePayload()`).
 */
it('keeps choice and pubkey out of security_attempts for a tampered ballot request', function () {
    $pubkey = bin2hex(random_bytes(32));
    $property = 'selection.'.TELEMETRY_CANDIDATE_ID;

    $request = Request::create('/livewire/update', 'POST', [
        'components' => [[
            'snapshot' => json_encode([
                'memo' => ['name' => 'association.election.ballot'],
                'data' => ['currentPubkey' => $pubkey, 'selection' => [TELEMETRY_CANDIDATE_ID => 'for']],
            ]),
            'updates' => [$property => 'against'],
        ]],
    ]);
    $request->setRouteResolver(fn () => null);
    app()->instance('request', $request);

    app(SecurityMonitor::class)->recordFromException(new CannotUpdateLockedPropertyException($property), $request);

    $attempt = SecurityAttempt::query()->sole();
    $stored = json_encode($attempt->getAttributes());

    expect($attempt->component_name)->toBe('association.election.ballot')
        ->and($attempt->exception_message)->toBe(SecurityMonitor::ELECTION_MESSAGE)
        ->and($attempt->payload)->toBeNull()
        ->and($attempt->target_property)->toBeNull()
        ->and($stored)->not->toContain((string) TELEMETRY_CANDIDATE_ID)
        ->not->toContain($pubkey)
        ->not->toContain('against');
});

/*
 * Mutation that must turn this red: switch off
 * `database.connections.sqlite.mask_bindings_in_exception_messages` — the
 * failed insert then names candidate and choice in its message.
 */
it('keeps choice, signature and pubkey out of the messages of a failed and of a refused cast', function () {
    [$round, $voter] = telemetryRound();
    $events = [
        signedElectionVote($round, $voter, [TELEMETRY_CANDIDATE_ID => 'for']),
        signedElectionVote($round, $voter, [TELEMETRY_CANDIDATE_ID => 'tampered']),
    ];

    DB::statement("CREATE TRIGGER refuse_votes BEFORE INSERT ON election_votes BEGIN SELECT RAISE(ABORT, 'vote refused'); END");

    $messages = [];

    foreach ($events as $event) {
        try {
            app(CastBallot::class)->handle($round, $voter, $event);
        } catch (Throwable $thrown) {
            for ($exception = $thrown; $exception !== null; $exception = $exception->getPrevious()) {
                $messages[] = get_class($exception).': '.$exception->getMessage();
            }
        }
    }

    expect(collect($messages)->first(fn (string $message) => str_starts_with($message, ElectionBallotWriteException::class)))->toContain('SQLSTATE')
        ->and(collect($messages)->contains(fn (string $message) => str_contains($message, 'vote refused')))->toBeFalse()
        ->and(collect($messages)->contains(fn (string $message) => str_contains($message, 'Ballot refused: '.ElectionBallotRefusal::InvalidChoices->value)))->toBeTrue();

    foreach ($messages as $message) {
        expect($message)->not->toContain((string) TELEMETRY_CANDIDATE_ID)
            ->not->toContain($events[0]['sig'])
            ->not->toContain($voter->pubkey)
            ->not->toMatch('/\b(for|tampered)\b/');
    }
});

/*
 * Mutation that must turn this red: switch off
 * `database.connections.sqlite.mask_bindings_in_exception_messages`
 * (B2-telemetry-exception-message). CastBallot re-throws a refused insert
 * without its cause, so the test above cannot see the flag; this one asks the
 * connection itself, which every other write of a ballot row goes through.
 */
it('keeps bound values out of the message of a failed query on the ballot table', function () {
    $message = null;

    try {
        // Unquoted on purpose: SQLite reads an unknown "quoted" name as a
        // string literal and would not fail at all.
        DB::select('select * from election_votes where no_such_column = ?', ['ballot-secret-j1d']);
    } catch (QueryException $exception) {
        $message = $exception->getMessage();
    }

    expect($message)->toContain('no_such_column')->toContain('SQL: select * from election_votes')
        ->not->toContain('ballot-secret-j1d');
});

/*
 * Mutation that must turn this red: drop the `tooManyAttempts()` check in
 * `CastBallot::throttle()`.
 */
it('throttles casting per member and leaves other members alone', function () {
    [$round, $voter] = telemetryRound();
    $other = electionVoter();

    for ($attempt = 1; $attempt <= CastBallot::MAX_ATTEMPTS; $attempt++) {
        expect(electionRefusal(fn () => castElectionBallot($round, $voter, [TELEMETRY_CANDIDATE_ID => 'nonsense'])))->toBe(ElectionBallotRefusal::InvalidChoices);
    }

    expect(electionRefusal(fn () => castElectionBallot($round, $voter, [TELEMETRY_CANDIDATE_ID => 'for'])))->toBe(ElectionBallotRefusal::TooManyAttempts)
        ->and(electionVoteCount($round))->toBe(0)
        ->and(electionRefusal(fn () => castElectionBallot($round, $other, [TELEMETRY_CANDIDATE_ID => 'for'])))->toBe(ElectionBallotRefusal::NotOnRoll);

    RateLimiter::clear('election-cast:'.$voter->getKey());

    expect(electionRefusal(fn () => castElectionBallot($round, $voter, [TELEMETRY_CANDIDATE_ID => 'for'])))->toBeNull();
});
