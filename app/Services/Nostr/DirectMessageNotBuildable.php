<?php

namespace App\Services\Nostr;

use InvalidArgumentException;

/**
 * A direct message that cannot be built for this recipient or this text.
 *
 * Distinct from NotificationKeyUnavailable on purpose: a missing key is a
 * configuration error of the whole installation, this is a property of one
 * message and one recipient.
 */
final class DirectMessageNotBuildable extends InvalidArgumentException
{
    public static function erasedRecipient(): self
    {
        return new self('The recipient has been erased; there is no key to write to.');
    }

    public static function invalidRecipientKey(): self
    {
        return new self('The recipient record does not hold a valid secp256k1 public key.');
    }

    public static function emptyText(): self
    {
        return new self('A direct message needs text.');
    }

    public static function invalidText(): self
    {
        return new self('The message text is not valid UTF-8.');
    }

    public static function textTooLong(int $bytes, int $limit): self
    {
        return new self("The message text is {$bytes} bytes long; the limit is {$limit}.");
    }

    public static function invalidTimestamp(): self
    {
        return new self('The rumor timestamp must be a positive Unix time that is not in the future.');
    }
}
