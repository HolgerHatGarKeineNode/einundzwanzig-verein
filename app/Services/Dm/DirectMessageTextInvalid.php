<?php

namespace App\Services\Dm;

use InvalidArgumentException;

/**
 * A message text that cannot be sent as it stands: a placeholder the type
 * does not know, a value that is missing, or no reply contact to name.
 */
final class DirectMessageTextInvalid extends InvalidArgumentException
{
    /**
     * @param  list<string>  $problems
     */
    public static function because(array $problems): self
    {
        return new self('The message text cannot be sent: '.implode('; ', $problems));
    }
}
