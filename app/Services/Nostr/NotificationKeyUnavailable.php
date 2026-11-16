<?php

namespace App\Services\Nostr;

use RuntimeException;

/**
 * The association's notification key is not there, or not a key.
 *
 * Thrown instead of skipping: a direct message that cannot be signed must end
 * as a visible failure, never as a send that silently did nothing. The
 * message never contains the configured value.
 */
final class NotificationKeyUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self(
            'No notification key is configured (NOSTR_NOTIFICATION_KEY). '
            .'Direct messages cannot be signed and relays cannot be authenticated to.',
        );
    }

    public static function malformed(): self
    {
        return new self(
            'The configured notification key (NOSTR_NOTIFICATION_KEY) is neither an nsec '
            .'nor 64 hex characters of a valid secp256k1 secret.',
        );
    }
}
