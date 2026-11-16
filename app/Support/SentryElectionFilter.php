<?php

namespace App\Support;

use App\Services\SecurityMonitor;
use Illuminate\Http\Request;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;
use Throwable;

/**
 * Keeps the ballot out of Sentry (E23). Wired in `config/sentry.php` as
 * `before_breadcrumb`, `before_send` and `before_send_transaction`.
 *
 * sentry-laravel's Livewire feature writes every action's arguments
 * (`Component call`), the public state (`Component hydrate`) and the mount
 * data (`Component mount`) as breadcrumbs; for `castBallot` that is the
 * signed vote, for `prepareBallot` the choices. An error event also carries
 * the request body, which holds the snapshots and the calls.
 *
 * Matched by component name, not by action name, so a new action or a new
 * component under `association.election.*` is covered without being listed:
 * a Livewire breadcrumb is dropped when it names an election component or
 * when its message cannot be read. An event that belongs to an election
 * leaves without request body, Livewire breadcrumbs, stack frame arguments
 * (on the exception stack traces and on the event's own stack trace) and the
 * HTTP bodies on its trace data. Whatever cannot be decided counts as
 * election — and Sentry drops a breadcrumb or event whose callback throws.
 * Dropping the breadcrumb itself matters wherever the scope outlives the
 * request: an event of a later, non-election request in the same process
 * would carry it, and `before_send` no longer strips there.
 *
 * An event belongs to an election when one of these holds:
 * - the request is a Livewire request with an election component in it
 *   (`SecurityMonitor::involvesElectionComponent()`);
 * - the matched route has the segment `election` in its name or its path
 *   (`association.election.admin`, `association/election/admin`);
 * - a stack frame, a `queue.job` breadcrumb or the transaction name names a
 *   class in a namespace with the segment `Election` (`App\Services\Election`)
 *   or in `App\Jobs\Dm`.
 *
 * Limit: apart from `App\Jobs\Dm`, recognition rests on a naming convention,
 * the segment `election` in a component, route or namespace name. A surface
 * that handles ballots or signer secrets under another name — a component
 * `association.vote`, a route `/wahl`, a service `App\Services\Ballot` — is
 * not recognised and its events leave unfiltered. A class is only seen when
 * it is on a stack trace or is the job being processed; an event raised in an
 * election service without a stack trace is not. The anonymous classes of
 * single-file components never match; they are covered by their request.
 * `SentryElectionFilterTest` pins today's election surfaces, so renaming one
 * out of the convention turns a test red.
 */
final class SentryElectionFilter
{
    private const LIVEWIRE_CATEGORY = 'livewire';

    /**
     * `Component call: <name>::<method>`, `Component hydrate: <name>`, … where
     * a Livewire 4 name may carry a namespace (`pages::association.election.index`).
     * The whole token is taken; `::` is read as a segment boundary.
     */
    private const LIVEWIRE_MESSAGE_PATTERN = '/^Component [a-z]+: (\S+)/u';

    /** A route path with the segment `election`. */
    private const ELECTION_PATH_PATTERN = '#(^|/)election(/|$)#u';

    /** A class in a namespace with the segment `Election`, or in `App\Jobs\Dm`. */
    private const ELECTION_CLASS_PATTERN = '/(^|\\\\)(Election|App\\\\Jobs\\\\Dm)\\\\/u';

    private const QUEUE_JOB_CATEGORY = 'queue.job';

    /** Trace data keys that hold an HTTP body. */
    private const HTTP_BODY_KEY_PATTERN = '/^http\.(request|response)\.body\./u';

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        return self::isElectionBreadcrumb($breadcrumb) ? null : $breadcrumb;
    }

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::belongsToElection($event) ? self::withoutBallot($event) : $event;
    }

    public static function beforeSendTransaction(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::beforeSend($event, $hint);
    }

    private static function isElectionBreadcrumb(Breadcrumb $breadcrumb): bool
    {
        if ($breadcrumb->getCategory() !== self::LIVEWIRE_CATEGORY) {
            return false;
        }

        if (preg_match(self::LIVEWIRE_MESSAGE_PATTERN, (string) $breadcrumb->getMessage(), $matches) !== 1) {
            return true;
        }

        return SecurityMonitor::isElectionComponent($matches[1]);
    }

    /**
     * Whether the event belongs to an election: by its request, its route or
     * the classes it names. An event that cannot be classified counts as one.
     */
    private static function belongsToElection(Event $event): bool
    {
        try {
            return self::duringElectionRequest(app('request')) || self::namesElectionClass($event);
        } catch (Throwable) {
            return true;
        }
    }

    private static function duringElectionRequest(Request $request): bool
    {
        if (SecurityMonitor::involvesElectionComponent($request)) {
            return true;
        }

        $route = $request->route();

        if ($route === null || is_string($route)) {
            return false;
        }

        return SecurityMonitor::isElectionComponent((string) $route->getName())
            || preg_match(self::ELECTION_PATH_PATTERN, $route->uri()) !== 0;
    }

    private static function namesElectionClass(Event $event): bool
    {
        $names = [(string) $event->getTransaction()];

        foreach (self::stacktraces($event) as $stacktrace) {
            foreach ($stacktrace->getFrames() as $frame) {
                $names[] = (string) $frame->getFunctionName();
            }
        }

        foreach ($event->getBreadcrumbs() as $breadcrumb) {
            if ($breadcrumb->getCategory() === self::QUEUE_JOB_CATEGORY) {
                $names[] = (string) ($breadcrumb->getMetadata()['resolved'] ?? '');
            }
        }

        foreach ($names as $name) {
            if (preg_match(self::ELECTION_CLASS_PATTERN, $name) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The event's own stack trace and those of its exceptions.
     *
     * @return list<Stacktrace>
     */
    private static function stacktraces(Event $event): array
    {
        $stacktraces = [$event->getStacktrace()];

        foreach ($event->getExceptions() as $exception) {
            $stacktraces[] = $exception->getStacktrace();
        }

        return array_values(array_filter($stacktraces));
    }

    private static function withoutBallot(Event $event): Event
    {
        $request = $event->getRequest();
        unset($request['data']);
        $event->setRequest($request);

        $event->setBreadcrumb(array_values(array_filter(
            $event->getBreadcrumbs(),
            fn (Breadcrumb $breadcrumb): bool => $breadcrumb->getCategory() !== self::LIVEWIRE_CATEGORY,
        )));

        foreach (self::stacktraces($event) as $stacktrace) {
            foreach ($stacktrace->getFrames() as $frame) {
                $frame->setVars([]);
            }
        }

        $trace = $event->getContexts()['trace'] ?? null;

        if (is_array($trace) && is_array($trace['data'] ?? null)) {
            $trace['data'] = self::withoutHttpBodies($trace['data']);
            $event->setContext('trace', $trace);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withoutHttpBodies(array $data): array
    {
        return array_filter($data, fn (string $key): bool => preg_match(self::HTTP_BODY_KEY_PATTERN, $key) === 0, ARRAY_FILTER_USE_KEY);
    }
}
