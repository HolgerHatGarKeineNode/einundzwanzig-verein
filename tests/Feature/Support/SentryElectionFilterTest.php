<?php

use App\Support\SentryElectionFilter;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\SentrySdk;
use Sentry\Stacktrace;

/*
 * SentryElectionFilter called the way the Sentry client calls it, on events
 * built here: a fake `before_send` harness. No client is involved, so no
 * event can leave the process; the hub of the test app has no DSN.
 *
 * The surfaces test pins today's election surfaces. Renaming one out of the
 * `election` convention (or moving a class out of its namespace) makes the
 * discovered list differ from the pinned one, or makes the filter stop
 * classifying it.
 */

const SENTRY_FILTER_NEEDLE = 'choices-7340033';

/** @var list<string> */
const SENTRY_ELECTION_ROUTES = [
    'association.election',
    'association.election.admin',
    'association.election.admin.protocol',
    'association.election.admin.screen',
];

/** @var list<string> */
const SENTRY_ELECTION_COMPONENTS = [
    'association.election.admin',
    'association.election.admin-form',
    'association.election.index',
    'association.election.protocol',
    'association.election.screen',
    'association.election.turnout',
];

/** @var list<string> */
const SENTRY_DM_COMPONENTS = [
    'association.messages',
    'association.messages.admin',
];

/** @var list<string> */
const SENTRY_ELECTION_CLASSES = [
    'App\Jobs\Dm\DispatchBulkDirectMessages',
    'App\Jobs\Dm\SendDirectMessage',
    'App\Jobs\Dm\SendInboxReply',
    'App\Services\Election\Candidacies',
    'App\Services\Election\CastBallot',
    'App\Services\Election\CastingDecision',
    'App\Services\Election\CloseRound',
    'App\Services\Election\Concerns\AuthorizesElectionActor',
    'App\Services\Election\CreateRunOff',
    'App\Services\Election\ElectionAuditLog',
    'App\Services\Election\ElectionSetup',
    'App\Services\Election\ElectionStewardship',
    'App\Services\Election\ElectoralRoll',
    'App\Services\Election\EnrolByHand',
    'App\Services\Election\OpenRound',
    'App\Services\Election\PurgeTestElection',
    'App\Services\Election\RecordCastingVote',
    'App\Services\Election\RefusalText',
    'App\Services\Election\RoundManifest',
    'App\Services\Election\RoundReport',
    'App\Services\Election\RunSheet',
    'App\Services\Election\Tally',
    'App\Services\Election\TallyResult',
    'App\Services\Election\TallyRound',
    'App\Services\Election\VoteEvent',
];

/**
 * An event that carries the needle in every place the filter clears: the
 * request body, a frame of the exception and a frame of the event's own
 * stack trace (`attach_stacktrace`).
 */
function sentryEventWithBallot(string $function = 'App\Http\Kernel::handle'): Event
{
    $frame = fn (): Frame => new Frame($function, 'app/Example.php', 10, vars: ['$choices' => SENTRY_FILTER_NEEDLE]);

    $event = Event::createEvent();
    $event->setRequest(['url' => 'https://verein.test/x', 'data' => ['choices' => SENTRY_FILTER_NEEDLE]]);
    $event->setExceptions([new ExceptionDataBag(new RuntimeException('failure'), new Stacktrace([$frame()]))]);
    $event->setStacktrace(new Stacktrace([$frame()]));

    return $event;
}

/**
 * Where the needle still stands in the event.
 *
 * @return list<string>
 */
function sentryBallotLeftIn(Event $event): array
{
    $left = [];

    if (str_contains(json_encode($event->getRequest()), SENTRY_FILTER_NEEDLE)) {
        $left[] = 'request';
    }

    foreach ($event->getExceptions() as $exception) {
        if (str_contains(json_encode(array_map(fn (Frame $frame): array => $frame->getVars(), $exception->getStacktrace()->getFrames())), SENTRY_FILTER_NEEDLE)) {
            $left[] = 'exception frames';
        }
    }

    if (str_contains(json_encode(array_map(fn (Frame $frame): array => $frame->getVars(), $event->getStacktrace()?->getFrames() ?? [])), SENTRY_FILTER_NEEDLE)) {
        $left[] = 'event frames';
    }

    return $left;
}

/**
 * Makes `$request` the current request.
 */
function sentryCurrentRequest(Request $request): void
{
    app()->instance('request', $request);
}

/**
 * The request of a page served by the route `$name`.
 */
function sentryRouteRequest(string $name): Request
{
    $route = Route::getRoutes()->getByName($name);
    expect($route)->not->toBeNull("route {$name} is gone");

    $request = Request::create('/'.$route->uri());
    $request->setRouteResolver(fn (): RoutingRoute => $route);

    return $request;
}

/**
 * A Livewire update request that carries the component `$name`.
 */
function sentryLivewireRequest(string $name): Request
{
    return Request::create('/livewire/update', 'POST', ['components' => [
        ['snapshot' => json_encode(['memo' => ['name' => $name]]), 'calls' => []],
    ]]);
}

function sentryLivewireBreadcrumb(string $message): Breadcrumb
{
    return new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'livewire', $message, ['$choices' => SENTRY_FILTER_NEEDLE]);
}

/**
 * Class names (FQCN) of every PHP file under `$directory`.
 *
 * @return list<string>
 */
function sentryClassesIn(string $directory): array
{
    return collect(File::allFiles(app_path($directory)))
        ->map(fn (SplFileInfo $file): string => 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getPathname(), app_path().'/')))
        ->all();
}

beforeEach(function () {
    // Nothing here may reach a transport: the app's hub has no DSN.
    expect(SentrySdk::getCurrentHub()->getClient()?->getOptions()->getDsn())->toBeNull();

    sentryCurrentRequest(Request::create('/'));
});

it('clears the frame arguments of the event stack trace as well as those of the exceptions', function () {
    sentryCurrentRequest(sentryLivewireRequest('association.election.index'));

    $event = SentryElectionFilter::beforeSend(sentryEventWithBallot());

    expect($event)->not->toBeNull()
        ->and(sentryBallotLeftIn($event))->toBe([]);
});

it('leaves an event outside every election alone', function () {
    sentryCurrentRequest(sentryRouteRequest('association.members.admin'));

    $event = SentryElectionFilter::beforeSend(sentryEventWithBallot());

    expect(sentryBallotLeftIn($event))->toBe(['request', 'exception frames', 'event frames']);
});

it('scrubs an event it cannot classify instead of passing it through or dropping it', function () {
    $request = Request::create('/');
    $request->setRouteResolver(fn () => throw new RuntimeException('route cannot be resolved'));
    sentryCurrentRequest($request);

    $event = SentryElectionFilter::beforeSend(sentryEventWithBallot());

    expect($event)->not->toBeNull()
        ->and($event->getRequest()['url'] ?? null)->toBe('https://verein.test/x')
        ->and(sentryBallotLeftIn($event))->toBe([]);
});

it('reads Livewire 4 namespaced component names in breadcrumbs', function (string $message, bool $kept) {
    expect(SentryElectionFilter::beforeBreadcrumb(sentryLivewireBreadcrumb($message)) !== null)->toBe($kept);
})->with([
    'a call on a namespaced ballot page' => ['Component call: pages::association.election.index::castBallot', false],
    'the state of a namespaced ballot page' => ['Component hydrate: pages::association.election.index', false],
    'the mount of a namespaced component ending in election' => ['Component mount: pages::association.board.election', false],
    'a call on a component ending in election' => ['Component call: association.board.election::castBallot', false],
    'a namespace named election' => ['Component hydrate: election::ballot', false],
    'a call on a namespaced DM page' => ['Component call: pages::association.messages.admin::send', true],
    'the state of a namespaced DM page' => ['Component hydrate: pages::association.messages.admin-compose', true],
    'a method that only contains the word' => ['Component call: association.members.admin::electionDate', true],
]);

it('reads the real names of the election and DM components, with and without a namespace', function () {
    $outcome = [];

    foreach ([...SENTRY_ELECTION_COMPONENTS, ...SENTRY_DM_COMPONENTS] as $component) {
        $name = app('livewire')->new($component)->getName();

        foreach (["Component call: {$name}::save", "Component hydrate: {$name}", "Component call: pages::{$name}::save", "Component mount: pages::{$name}"] as $message) {
            $outcome[$message] = SentryElectionFilter::beforeBreadcrumb(sentryLivewireBreadcrumb($message)) === null ? 'dropped' : 'kept';
        }
    }

    $expected = [];

    foreach ([...SENTRY_ELECTION_COMPONENTS, ...SENTRY_DM_COMPONENTS] as $component) {
        foreach (["Component call: {$component}::save", "Component hydrate: {$component}", "Component call: pages::{$component}::save", "Component mount: pages::{$component}"] as $message) {
            $expected[$message] = in_array($component, SENTRY_ELECTION_COMPONENTS, true) ? 'dropped' : 'kept';
        }
    }

    expect($outcome)->toBe($expected);
});

it('classifies every election route of today as election', function () {
    $discovered = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'association/election')
            || in_array($route->getAction('livewire_component'), SENTRY_ELECTION_COMPONENTS, true))
        ->map(fn (RoutingRoute $route): string => (string) $route->getName())
        ->sort()->values()->all();

    $outcome = collect(SENTRY_ELECTION_ROUTES)->mapWithKeys(function (string $name): array {
        sentryCurrentRequest(sentryRouteRequest($name));

        return [$name => sentryBallotLeftIn(SentryElectionFilter::beforeSend(sentryEventWithBallot()))];
    })->all();

    expect($discovered)->toBe(SENTRY_ELECTION_ROUTES)
        ->and($outcome)->toBe(array_fill_keys(SENTRY_ELECTION_ROUTES, []));
});

it('classifies every election component of today as election', function () {
    // Single-file components; the other views in the folder are partials.
    $discovered = collect(File::files(resource_path('views/livewire/association/election')))
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'extends Component'))
        ->map(fn (SplFileInfo $file): string => 'association.election.'.Str::before($file->getFilename(), '.blade.php'))
        ->sort()->values()->all();

    $outcome = collect(SENTRY_ELECTION_COMPONENTS)->mapWithKeys(function (string $component): array {
        $name = app('livewire')->new($component)->getName();
        sentryCurrentRequest(sentryLivewireRequest($name));

        return [$component => [
            'name' => $name,
            'event' => sentryBallotLeftIn(SentryElectionFilter::beforeSend(sentryEventWithBallot())),
            'breadcrumb' => SentryElectionFilter::beforeBreadcrumb(sentryLivewireBreadcrumb("Component call: {$name}::save")) === null ? 'dropped' : 'kept',
        ]];
    })->all();

    expect($discovered)->toBe(SENTRY_ELECTION_COMPONENTS)
        ->and($outcome)->toBe(collect(SENTRY_ELECTION_COMPONENTS)->mapWithKeys(fn (string $component): array => [$component => ['name' => $component, 'event' => [], 'breadcrumb' => 'dropped']])->all());
});

it('classifies every election service and DM job class of today as election', function () {
    $discovered = collect([...sentryClassesIn('Services/Election'), ...sentryClassesIn('Jobs/Dm')])->sort()->values()->all();

    $outcome = collect(SENTRY_ELECTION_CLASSES)->mapWithKeys(function (string $class): array {
        $byFrame = SentryElectionFilter::beforeSend(sentryEventWithBallot("{$class}::handle"));

        $byJob = sentryEventWithBallot();
        $byJob->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'queue.job', 'Processing queue job', ['resolved' => $class])]);

        $byTransaction = sentryEventWithBallot();
        $byTransaction->setTransaction($class);

        return [$class => [
            'exists' => class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class),
            'frame' => sentryBallotLeftIn($byFrame),
            'job' => sentryBallotLeftIn(SentryElectionFilter::beforeSend($byJob)),
            'transaction' => sentryBallotLeftIn(SentryElectionFilter::beforeSend($byTransaction)),
        ]];
    })->all();

    expect($discovered)->toBe(SENTRY_ELECTION_CLASSES)
        ->and($outcome)->toBe(array_fill_keys(SENTRY_ELECTION_CLASSES, ['exists' => true, 'frame' => [], 'job' => [], 'transaction' => []]));
});

it('does not take a class outside the election namespaces for one', function (string $function) {
    expect(sentryBallotLeftIn(SentryElectionFilter::beforeSend(sentryEventWithBallot($function))))->toBe(['request', 'exception frames', 'event frames']);
})->with([
    'the election model' => ['App\Models\Election::rounds'],
    'a round model' => ['App\Models\ElectionRound::publishedVoteEventIds'],
    'another job' => ['App\Jobs\SyncMembers::handle'],
]);

/*
 * preg_match() never throws: on an engine error it returns false. The
 * patterns carry `/u`, so a subject that is not valid UTF-8 is a
 * deterministic engine error; each case feeds one into one classifier.
 */
it('counts a regex engine error as election, since preg_match returns false instead of throwing', function (Closure $arrange) {
    $event = sentryEventWithBallot();
    $arrange($event);

    expect(preg_match('/x/u', "\xff"))->toBeFalse()
        ->and(sentryBallotLeftIn(SentryElectionFilter::beforeSend($event)))->toBe([]);
})->with([
    'route name' => [function (): void {
        $request = Request::create('/members');
        $request->setRouteResolver(fn (): RoutingRoute => (new RoutingRoute('GET', 'members', []))->name("members\xff"));
        sentryCurrentRequest($request);
    }],
    'route path' => [function (): void {
        $request = Request::create('/members');
        $request->setRouteResolver(fn (): RoutingRoute => (new RoutingRoute('GET', "members\xff", []))->name('members'));
        sentryCurrentRequest($request);
    }],
    'class name' => [function (Event $event): void {
        sentryCurrentRequest(sentryRouteRequest('association.members.admin'));
        $event->setTransaction("App\\Http\xff");
    }],
]);

it('drops a trace data key it cannot read, instead of keeping it', function () {
    sentryCurrentRequest(sentryLivewireRequest('association.election.index'));
    $event = sentryEventWithBallot();
    $event->setContext('trace', ['data' => ["http.request.body.\xff" => SENTRY_FILTER_NEEDLE, 'http.request.method' => 'POST']]);

    expect(SentryElectionFilter::beforeSend($event)->getContexts()['trace']['data'])->toBe(['http.request.method' => 'POST']);
});

it('reads a namespace separator as a segment boundary in request component names', function (string $name) {
    sentryCurrentRequest(sentryLivewireRequest($name));

    expect(sentryBallotLeftIn(SentryElectionFilter::beforeSend(sentryEventWithBallot())))->toBe([]);
})->with(['pages::election.ballot', 'election::ballot', 'pages::association.election.index']);

it('scrubs an event when a Livewire request carries a snapshot it cannot read', function () {
    sentryCurrentRequest(Request::create('/livewire/update', 'POST', ['components' => [
        ['snapshot' => '{not json', 'calls' => []],
    ]]));

    expect(sentryBallotLeftIn(SentryElectionFilter::beforeSend(sentryEventWithBallot())))->toBe([]);
});

it('scrubs an event when reading the Livewire request throws', function () {
    $request = new class extends Request
    {
        public function input($key = null, $default = null): mixed
        {
            throw new RuntimeException('input cannot be read');
        }
    };
    $request->setRouteResolver(fn (): RoutingRoute => Route::getRoutes()->getByName('association.members.admin'));
    sentryCurrentRequest($request);

    expect(sentryBallotLeftIn(SentryElectionFilter::beforeSend(sentryEventWithBallot())))->toBe([]);
});
