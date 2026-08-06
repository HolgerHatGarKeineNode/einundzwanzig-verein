<?php

namespace App\Http\Middleware;

use App\Support\ApiIdentity;
use App\Support\Nip98;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes WHO the request is for.
 *
 * Runs after VerifyApiClient on purpose: an unknown application is turned
 * away before a Schnorr verification is spent on its request.
 *
 * The pubkey is put on the request as an attribute rather than into the
 * session or a static — /api/v1 is stateless, and an attribute dies with the
 * request that carried it.
 */
class VerifyNip98
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $pubkey = Nip98::verify($request);

        $request->attributes->set(ApiIdentity::PUBKEY_ATTRIBUTE, $pubkey);

        ApiIdentity::guardClaimedSubject($request);

        return $next($request);
    }
}
