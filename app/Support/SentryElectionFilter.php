<?php

namespace App\Support;

use App\Services\SecurityMonitor;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
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
 * when its message cannot be read. An event of a request that involves an
 * election component leaves without request body, Livewire breadcrumbs,
 * stack frame arguments and the HTTP bodies on its trace data. Whatever
 * cannot be decided counts as election — and Sentry drops a breadcrumb or
 * event whose callback throws.
 * Dropping the breadcrumb itself matters wherever the scope outlives the
 * request: an event of a later, non-election request in the same process
 * would carry it, and `before_send` no longer strips there.
 */
final class SentryElectionFilter
{
    private const LIVEWIRE_CATEGORY = 'livewire';

    /** `Component call: <name>::<method>`, `Component hydrate: <name>`, … */
    private const LIVEWIRE_MESSAGE_PATTERN = '/^Component [a-z]+: ([^\s:]+)/';

    /** Trace data keys that hold an HTTP body. */
    private const HTTP_BODY_KEY_PATTERN = '/^http\.(request|response)\.body\./';

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        return self::isElectionBreadcrumb($breadcrumb) ? null : $breadcrumb;
    }

    public static function beforeSend(Event $event, ?EventHint $hint = null): ?Event
    {
        return self::duringElectionRequest() ? self::withoutBallot($event) : $event;
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
     * Whether the current request is a Livewire request with an election
     * component in it. A request that cannot be resolved counts as one.
     */
    private static function duringElectionRequest(): bool
    {
        try {
            return SecurityMonitor::involvesElectionComponent(app('request'));
        } catch (Throwable) {
            return true;
        }
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

        foreach ($event->getExceptions() as $exception) {
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
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
        return array_filter($data, fn (string $key): bool => preg_match(self::HTTP_BODY_KEY_PATTERN, $key) !== 1, ARRAY_FILTER_USE_KEY);
    }
}
