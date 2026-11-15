<?php

namespace App\Services\Dm;

use RuntimeException;

/**
 * The audience resolved at the start is not the one the board confirmed:
 * somebody joined, paid or left since the dry run. Nothing was written.
 */
final class DirectMessageAudienceChanged extends RuntimeException
{
    public function __construct(public readonly int $confirmedCount, public readonly int $currentCount)
    {
        parent::__construct("The audience changed since the dry run: {$confirmedCount} confirmed, {$currentCount} now.");
    }
}
