<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The api.v1 throttle. Does the same work as `throttle:<limiter>` and exists
 * for exactly one reason: to stay OUT of Kernel::$middlewarePriority.
 *
 * Laravel re-sorts a route's middleware by that list before running it
 * (SortedMiddleware). ThrottleRequests is on the list, and a second
 * ThrottleRequests further down the chain gets pulled up next to the first —
 * past everything unlisted in between. With `throttle:api-v1` written into
 * the group, the measured chain was
 *
 *   throttle:api, throttle:api-v1, SubstituteBindings, VerifyApiClient, VerifyNip98
 *
 * i.e. the api-v1 quota was charged before either identity existed: every
 * request would have landed in one shared bucket keyed "unresolved" plus the
 * caller's IP, which is precisely the per-IP counting this phase set out to
 * remove.
 *
 * Priority matches parents and interfaces too, so extending ThrottleRequests
 * would inherit the same fate — hence delegation. Adding the verifiers to the
 * priority list instead was tried and rejected: it pushes the outer
 * throttle:api behind them, leaving /api/v1 traffic that never gets past the
 * client check with no IP limit at all.
 */
class ThrottleApiV1
{
    public function __construct(private readonly ThrottleRequests $throttle) {}

    /**
     * @param  Closure(Request): (Response)  $next
     * @param  string  $limiter  Name of a limiter registered in AppServiceProvider
     */
    public function handle(Request $request, Closure $next, string $limiter): Response
    {
        return $this->throttle->handle($request, $next, $limiter);
    }
}
