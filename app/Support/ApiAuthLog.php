<?php

namespace App\Support;

use App\Exceptions\Nip98Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Server-side record of refused /api/v1 credentials.
 *
 * `VerifyApiClient` already logs its own rejections. The signature half did
 * not, which meant an attack on the NIP-98 layer — forged signatures, replayed
 * events, events signed for a foreign origin — left no trace at all: the
 * attacker saw a 401 and so did nobody else.
 *
 * WHAT IS RECORDED AND WHAT IS NOT. The entry names the failed condition
 * (`Nip98Exception::$reason`), the calling application and the network origin.
 * That is enough to tell "one client is misbuilding its events" from "somebody
 * is probing the signature check", which is the whole reason to keep the log.
 *
 * It deliberately carries no personal data: no e-mail address, no application
 * prose, no request body — a log line about a refused request must not become
 * the place where the data of a refused request is stored. Nor does it carry
 * the client key, for the same reason `VerifyApiClient` keeps it out: a secret
 * that reaches the log has been shared with everyone who can read the log.
 *
 * The pubkey is left out too, and that is not an oversight. At the moment of
 * failure the signature has NOT been verified, so the pubkey in the event is
 * whatever the sender wrote there. Recording it would file an unverified,
 * attacker-chosen identity as if it were established — the log would name a
 * suspect the attacker picked.
 */
class ApiAuthLog
{
    public static function nip98Failure(Nip98Exception $exception, Request $request): void
    {
        Log::warning('api.v1 nip-98 rejected', [
            'reason' => $exception->reason,
            'client' => ApiIdentity::client($request),
            'ip' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $exception->getStatusCode(),
        ]);
    }
}
