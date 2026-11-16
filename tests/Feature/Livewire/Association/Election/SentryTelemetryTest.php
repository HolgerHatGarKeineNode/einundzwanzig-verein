<?php

use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\CastBallot;
use App\Services\Election\ElectoralRoll;
use App\Services\Election\OpenRound;
use App\Support\NostrAuth;
use App\Support\SentryElectionFilter;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as Events;
use Livewire\Livewire;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\HttpSpanDataCollector;
use Sentry\Event;
use Sentry\Frame;
use Sentry\Laravel\Features\LivewirePackageIntegration;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\Laravel\Integration;
use Sentry\SentrySdk;
use Sentry\Serializer\PayloadSerializer;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';

/*
 * E23 through the page: sentry-laravel's Livewire feature writes every
 * action's arguments, the public state and the mount data as breadcrumbs,
 * and an error event carries the request body. None of it may hold a ballot
 * of an `association.election.*` component (SentryElectionFilter).
 *
 * The client is built the way production builds it — the app's
 * ClientBuilder with the shipped config/sentry.php and the SDK's default
 * integrations, request integration included — with a fake DSN and an
 * in-memory transport, so nothing leaves the machine. What is asserted on is
 * the serialized payload the transport would send.
 *
 * Mutation that must turn every cast test red: drop the three
 * `before_*` entries from config/sentry.php.
 *
 * The candidate id 7340033 is chosen so it cannot occur by accident.
 */

const SENTRY_CANDIDATE_ID = 7340033;

/**
 * @param  array<string, mixed>  $overrides  config on top of the shipped config/sentry.php
 * @return ArrayObject<int, Event>
 */
function bootSentryLikeProduction(array $overrides = []): ArrayObject
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

    config(['sentry.dsn' => 'https://public@sentry.invalid/1', 'sentry.traces_sample_rate' => 1.0]);

    config($overrides);

    app()->extend(ClientBuilder::class, fn (ClientBuilder $builder): ClientBuilder => $builder->setTransport($transport));
    app()->forgetInstance(HubInterface::class);
    app(HubInterface::class);

    // What Sentry's ServiceProvider::bootFeatures() does once a DSN is set.
    $feature = app(LivewirePackageIntegration::class);
    expect($feature->isApplicable())->toBeTrue();
    $feature->boot();

    // With a DSN, Sentry pushes SetRequestMiddleware onto the HTTP kernel; it
    // hands the request to the request integration as PSR-7. Livewire's test
    // requests skip middleware and carry form parameters, so the same
    // instance is bound here, inside each update request, the way the
    // browser sends it: a raw JSON body with its Content-Length.
    app('livewire')->listen('hydrate', function (): void {
        $body = json_encode(request()->all());

        app()->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, app(ServerRequestInterface::class)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Length', (string) strlen($body))
            ->withParsedBody(null)
            ->withBody(Utils::streamFor($body)));
    });

    // With body collection on, sentry-laravel's tracing middleware puts the
    // request and the response on the transaction
    // (`Middleware::collectRequestData()`, `collectResponseData()`). It is
    // skipped like every middleware, so the same calls run here.
    if (array_key_exists('sentry.data_collection', $overrides)) {
        app('livewire')->listen('hydrate', function (): void {
            SentrySdk::getCurrentHub()->getTransaction()?->setData(HttpSpanDataCollector::collectServerRequest(
                DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub()),
                (new LaravelRequestFetcher)->fetchRequest(),
            ));
        });

        Events::listen(RequestHandled::class, function (RequestHandled $handled): void {
            SentrySdk::getCurrentHub()->getTransaction()?->setData(HttpSpanDataCollector::collectBody(
                DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub()),
                HttpMessageType::outgoingResponse(),
                $handled->response->getContent(),
                (string) $handled->response->headers->get('Content-Type', ''),
            ));
        });
    }

    return $events;
}

/**
 * Each captured event as the transport would put it on the wire.
 *
 * @param  ArrayObject<int, Event>  $events
 * @return list<string>
 */
function sentryPayloads(ArrayObject $events): array
{
    $serializer = new PayloadSerializer(SentrySdk::getCurrentHub()->getClient()->getOptions());

    return array_map(fn (Event $event): string => $serializer->serialize($event), $events->getArrayCopy());
}

/**
 * The sampled transaction one update request runs in.
 */
function sentryTransaction(): Transaction
{
    $hub = SentrySdk::getCurrentHub();
    $transaction = $hub->startTransaction(TransactionContext::make()->setName('POST /livewire/update')->setSampled(true));
    $hub->setSpan($transaction);

    return $transaction;
}

/**
 * An open round with one approved candidate under the id 7340033 and a
 * voter on its roll, logged in.
 *
 * @return array{0: ElectionRound, 1: EinundzwanzigPleb}
 */
function sentryBallotRound(): array
{
    $voter = electionVoter();
    $election = Election::factory()->announced()->create();
    $round = draftElectionRound(candidates: 1, election: $election);
    DB::table('election_candidates')->where('election_round_id', $round->id)->update(['id' => SENTRY_CANDIDATE_ID, 'status' => 'approved']);
    $round = app(OpenRound::class)->handle($round->fresh());

    electionSecretFor($voter);
    NostrAuth::login($voter->pubkey);

    return [$round, $voter->refresh()];
}

/**
 * What must not appear anywhere in a payload.
 *
 * @param  array<string, mixed>  $event
 * @return array<string, string>
 */
function ballotNeedles(EinundzwanzigPleb $voter, array $event): array
{
    return [
        'pubkey' => $voter->pubkey,
        'candidate id' => (string) SENTRY_CANDIDATE_ID,
        'sig' => $event['sig'],
        'event id' => $event['id'],
        'event JSON' => json_encode($event),
    ];
}

afterEach(function () {
    SentrySdk::setCurrentHub(new Hub);
});

$sentryConfigs = [
    'as shipped' => [[]],
    'with every request body' => [['sentry.max_request_body_size' => 'always']],
    'collecting every body' => [['sentry.data_collection' => [], 'sentry.max_request_body_size' => 'always']],
];

it('keeps the ballot of prepareBallot and castBallot out of every breadcrumb and transaction', function (array $overrides) {
    [$round, $voter] = sentryBallotRound();
    $event = signedElectionVote($round, $voter, [SENTRY_CANDIDATE_ID => 'for']);
    $events = bootSentryLikeProduction($overrides);

    $page = Livewire::test('association.election.index');

    $transaction = sentryTransaction();
    $prepared = $page->call('prepareBallot', $round->id, [SENTRY_CANDIDATE_ID => 'for'])->effects['returns'][0] ?? null;
    $transaction->finish();

    $transaction = sentryTransaction();
    $answer = $page->call('castBallot', $round->id, $event)->effects['returns'][0] ?? null;

    // Positive control: a breadcrumb outside Livewire is recorded.
    Integration::addBreadcrumb(new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'test', 'after the cast'));
    SentrySdk::getCurrentHub()->captureMessage('after the cast');
    $transaction->finish();

    // A later request in the same process, without an election component:
    // `before_send` leaves its events alone, the scope still holds the
    // breadcrumbs of the cast. It runs outside the cast's transaction, and
    // SetRequestMiddleware binds its own PSR-7 request.
    app()->instance('request', Request::create('/'));
    app()->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, app(ServerRequestInterface::class));
    SentrySdk::getCurrentHub()->setSpan(null);
    SentrySdk::getCurrentHub()->captureMessage('in the next request');

    $payloads = sentryPayloads($events);

    expect($prepared['event']['tags'] ?? [])->toContain(['vote', (string) SENTRY_CANDIDATE_ID, 'for'])
        ->and($answer)->toBe(['status' => 'counted'])
        ->and(electionVoteCount($round))->toBe(1)
        ->and(collect($events)->map(fn (Event $captured): string => (string) $captured->getType())->sort()->values()->all())->toBe(['event', 'event', 'transaction', 'transaction'])
        ->and(implode("\n", $payloads))->toContain('after the cast')->toContain('in the next request');

    foreach ($payloads as $payload) {
        foreach (ballotNeedles($voter, $event) as $what => $needle) {
            expect(str_contains($payload, $needle))->toBeFalse("Sentry payload holds the {$what}");
        }

        expect($payload)->not->toContain('Component call: association.election')
            ->not->toContain('Component hydrate: association.election');
    }
})->with($sentryConfigs);

it('sends an error event inside the cast request without request body, stack arguments or Livewire breadcrumbs', function (array $overrides) {
    [$round, $voter] = sentryBallotRound();
    $event = signedElectionVote($round, $voter, [SENTRY_CANDIDATE_ID => 'for']);
    $events = bootSentryLikeProduction($overrides);

    // An error reported while the cast request is handled, after the vote
    // is in: any failure later in the same request looks like this.
    app()->bind(CastBallot::class, fn () => new class(app(ElectoralRoll::class)) extends CastBallot
    {
        public function handle(ElectionRound $round, EinundzwanzigPleb $voter, #[SensitiveParameter] array $event): void
        {
            parent::handle($round, $voter, $event);

            Sentry\captureException(new RuntimeException('failure inside the cast request'));
        }
    });

    // The most talkative setting: stack frames carry the call arguments.
    $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

    try {
        $page = Livewire::test('association.election.index');
        $transaction = sentryTransaction();
        $answer = $page->call('castBallot', $round->id, $event)->effects['returns'][0] ?? null;
        $transaction->finish();
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
    }

    $error = collect($events)->first(fn (Event $captured): bool => (string) $captured->getType() === 'event');
    $payloads = sentryPayloads($events);

    expect($answer)->toBe(['status' => 'counted'])
        ->and($error)->not->toBeNull()
        ->and($error->getExceptions()[0]->getValue())->toBe('failure inside the cast request')
        // Positive control: the request integration ran on this event.
        ->and($error->getRequest()['url'] ?? '')->toEndWith(app('livewire')->getUpdateUri())
        ->and($error->getRequest())->not->toHaveKey('data');

    foreach ($payloads as $payload) {
        foreach (ballotNeedles($voter, $event) as $what => $needle) {
            expect(str_contains($payload, $needle))->toBeFalse("Sentry payload holds the {$what}");
        }
    }
})->with($sentryConfigs);

it('sends a message event with an attached stack trace inside the cast request without its frame arguments', function () {
    [$round, $voter] = sentryBallotRound();
    $event = signedElectionVote($round, $voter, [SENTRY_CANDIDATE_ID => 'for']);
    $events = bootSentryLikeProduction(['sentry.attach_stacktrace' => true]);

    // A message, not an exception: its frames sit on the event's own stack
    // trace, and with arguments kept they carry the signed vote.
    app()->bind(CastBallot::class, fn () => new class(app(ElectoralRoll::class)) extends CastBallot
    {
        public function handle(ElectionRound $round, EinundzwanzigPleb $voter, array $event): void
        {
            parent::handle($round, $voter, $event);

            Sentry\captureMessage('message inside the cast request');
        }
    });

    $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

    try {
        $page = Livewire::test('association.election.index');
        $answer = $page->call('castBallot', $round->id, $event)->effects['returns'][0] ?? null;
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
    }

    $message = collect($events)->first(fn (Event $captured): bool => $captured->getMessage() === 'message inside the cast request');

    expect($answer)->toBe(['status' => 'counted'])
        ->and($message)->not->toBeNull()
        // Positive control: the event has its own stack trace, through the cast.
        ->and($message->getExceptions())->toBe([])
        ->and(collect($message->getStacktrace()?->getFrames() ?? [])->contains(fn (Frame $frame): bool => str_ends_with((string) $frame->getFunctionName(), '::handle')))->toBeTrue();

    foreach (sentryPayloads($events) as $payload) {
        foreach (ballotNeedles($voter, $event) as $what => $needle) {
            expect(str_contains($payload, $needle))->toBeFalse("Sentry payload holds the {$what}");
        }
    }
});

it('sends an event it cannot classify scrubbed, neither unfiltered nor dropped', function () {
    $events = bootSentryLikeProduction(['sentry.attach_stacktrace' => true]);

    $request = Request::create('/');
    $request->setRouteResolver(fn () => throw new RuntimeException('route cannot be resolved'));
    app()->instance('request', $request);

    // Built at runtime: the frames carry the source lines around them.
    $choices = 'choices-'.SENTRY_CANDIDATE_ID;
    $ignoreArgs = ini_set('zend.exception_ignore_args', '0');

    try {
        (function (string $choices): void {
            Sentry\captureMessage('unclassifiable');
        })($choices);
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
    }

    $payloads = sentryPayloads($events);

    expect($events)->toHaveCount(1)
        ->and($payloads[0])->toContain('unclassifiable')
        ->and($payloads[0])->not->toContain($choices);
});

it('keeps the Livewire breadcrumbs of the board election components out of Sentry', function (string $component, Closure $parameters, ?array $call) {
    [$board] = electionBoard(1);
    NostrAuth::login($board->pubkey);
    $round = openElectionRound(candidates: 1, election: Election::factory()->announced()->create());
    $events = bootSentryLikeProduction();

    $page = Livewire::test($component, $parameters($round));
    $call === null ? $page->call('$refresh') : $page->call($call[0], ...array_map(fn (Closure $argument) => $argument($round), $call[1]));
    $page->assertOk();

    SentrySdk::getCurrentHub()->captureMessage('after the board action');

    $breadcrumbs = collect($events)->flatMap(fn (Event $captured): array => $captured->getBreadcrumbs());

    expect($events)->toHaveCount(1)
        ->and($breadcrumbs->filter(fn (Breadcrumb $breadcrumb): bool => $breadcrumb->getCategory() === 'livewire')->all())->toBe([]);
})->with([
    'admin' => ['association.election.admin', fn () => [], ['selectElection', [fn (ElectionRound $round) => $round->election_event_id]]],
    'screen' => ['association.election.screen', fn (ElectionRound $round) => ['election' => $round->election_event_id], null],
    'turnout' => ['association.election.turnout', fn (ElectionRound $round) => ['roundId' => $round->id], null],
    'protocol' => ['association.election.protocol', fn (ElectionRound $round) => ['election' => $round->election_event_id], null],
]);

it('drops a Livewire breadcrumb by component name, and an unreadable one, but keeps the rest', function (string $category, string $message, bool $kept) {
    $breadcrumb = new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, $category, $message, ['$event' => ['pubkey' => 'x']]);

    expect(SentryElectionFilter::beforeBreadcrumb($breadcrumb) !== null)->toBe($kept);
})->with([
    'a call of the ballot page' => ['livewire', 'Component call: association.election.index::castBallot', false],
    'a future action of the ballot page' => ['livewire', 'Component call: association.election.index::somethingNew', false],
    'the state of the share view' => ['livewire', 'Component hydrate: association.election.screen', false],
    'the mount of the minutes' => ['livewire', 'Component mount: association.election.protocol', false],
    'an unreadable Livewire message' => ['livewire', 'castBallot was called', false],
    'another component' => ['livewire', 'Component call: association.members.admin::save', true],
    'not Livewire' => ['test', 'Component call: association.election.index::castBallot', true],
]);
