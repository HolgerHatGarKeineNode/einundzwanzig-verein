<?php

namespace App\Http\Middleware;

use App\Support\ApiIdentity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Establishes WHICH APPLICATION is calling.
 *
 * First middleware in the api.v1 group: an unknown caller is turned away
 * before any data operation and before the expensive NIP-98 verification.
 *
 * The key itself never leaves this class — not into the log, not into the
 * response, not into the rate-limiter key. What travels on is the resolved
 * name; without it there is no attribution, and attribution is half the
 * reason the key exists at all.
 *
 * Fail-closed by construction: an unset, empty or unparsable API_CLIENT_KEYS
 * yields an empty map, and an empty map matches nothing. "Nothing configured"
 * must never mean "everyone may".
 */
class VerifyApiClient
{
    public const HEADER = 'X-Api-Key';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $presented = (string) $request->header(self::HEADER, '');

        if ($presented === '') {
            $this->reject($request, 'missing_header');
        }

        $configured = config('einundzwanzig.config.api_client_keys');

        if (! is_array($configured) || $configured === []) {
            $this->reject($request, 'no_keys_configured');
        }

        $name = $this->resolveName($configured, $presented);

        if ($name === null) {
            $this->reject($request, 'unknown_key');
        }

        $request->attributes->set(ApiIdentity::CLIENT_ATTRIBUTE, $name);

        return $next($request);
    }

    /**
     * Compare against every configured key with hash_equals and without an
     * early exit, so neither the value nor the position of the matching entry
     * leaks through response timing.
     *
     * @param  array<array-key, mixed>  $configured
     */
    private function resolveName(array $configured, string $presented): ?string
    {
        $name = null;

        foreach ($configured as $candidateName => $candidateKey) {
            if (! is_string($candidateKey) || $candidateKey === '') {
                continue;
            }

            if (hash_equals($candidateKey, $presented)) {
                $name = (string) $candidateName;
            }
        }

        return $name;
    }

    /**
     * @throws UnauthorizedHttpException
     */
    private function reject(Request $request, string $reason): never
    {
        Log::warning('api.v1 client key rejected', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        throw new UnauthorizedHttpException('X-Api-Key', 'Unauthorized.');
    }
}
