<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A NIP-98 request was refused.
 *
 * The `reason` is the machine-readable name of the single condition that
 * failed. It never reaches the client: the HTTP body carries only the generic
 * status message, so the endpoint does not tell an attacker which of the
 * checks tripped. Tests assert against `reason` to prove that a negative case
 * fails at the condition it claims to test and not at an earlier guard — the
 * exact mistake that produced a falsely green test in P1.
 */
class Nip98Exception extends HttpException
{
    public const MISSING_AUTHORIZATION = 'missing_authorization';

    public const MALFORMED_AUTHORIZATION = 'malformed_authorization';

    public const MALFORMED_EVENT = 'malformed_event';

    public const INVALID_KIND = 'invalid_kind';

    public const METHOD_MISMATCH = 'method_mismatch';

    public const URL_MISMATCH = 'url_mismatch';

    public const STALE_EVENT = 'stale_event';

    public const UNSUPPORTED_CONTENT_TYPE = 'unsupported_content_type';

    public const UNREADABLE_BODY = 'unreadable_body';

    public const PAYLOAD_MISMATCH = 'payload_mismatch';

    public const APPLICATION_URL_UNREADABLE = 'application_url_unreadable';

    public const INVALID_EVENT_ID = 'invalid_event_id';

    public const INVALID_SIGNATURE = 'invalid_signature';

    public const REPLAYED = 'replayed';

    public const REPLAY_STORE_UNAVAILABLE = 'replay_store_unavailable';

    public function __construct(public readonly string $reason, int $statusCode = 401)
    {
        parent::__construct($statusCode, match ($statusCode) {
            401 => 'Unauthorized.',
            415 => 'Unsupported Media Type.',
            default => 'Service Unavailable.',
        });
    }

    /**
     * /api/v1 speaks JSON and nothing else.
     *
     * Not pedantry: under a real SAPI, PHP consumes a multipart body into
     * $_POST before any application code runs, and php://input is then empty.
     * A signature bound to the raw body would cover nothing at all, so a
     * request whose body we cannot see in full is refused at the door.
     */
    public static function unsupportedContentType(): self
    {
        return new self(self::UNSUPPORTED_CONTENT_TYPE, 415);
    }

    /**
     * config('app.url') is missing or unusable, so the absolute URL the `u`
     * tag has to match cannot be constructed. Closed, not open.
     */
    public static function applicationUrlUnreadable(): self
    {
        return new self(self::APPLICATION_URL_UNREADABLE, 503);
    }

    /**
     * The replay lock could not be taken because its cache store is broken or
     * misconfigured. Closed, not open: without a working lock the replay
     * defence is gone entirely, and a request we cannot check is a request we
     * must not serve.
     */
    public static function replayStoreUnavailable(): self
    {
        return new self(self::REPLAY_STORE_UNAVAILABLE, 503);
    }
}
