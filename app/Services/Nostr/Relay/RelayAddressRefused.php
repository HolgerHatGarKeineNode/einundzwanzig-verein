<?php

namespace App\Services\Nostr\Relay;

use RuntimeException;

/**
 * A relay URL we will not connect to. The message names the reason, not the
 * resolved address list.
 */
final class RelayAddressRefused extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("refused: {$reason}");
    }
}
