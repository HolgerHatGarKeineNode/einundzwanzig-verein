<?php

namespace App\Exceptions;

use App\Enums\ElectionBallotRefusal;
use RuntimeException;

/**
 * A ballot was not taken in.
 *
 * Carries the reason and NOTHING else on purpose: no member, no round, no
 * choice. Exceptions end up in logs and error trackers, and a message naming
 * the voter next to what was on the ballot would be the one record that links
 * the two.
 */
class ElectionBallotRefusedException extends RuntimeException
{
    public function __construct(public readonly ElectionBallotRefusal $reason)
    {
        parent::__construct('Ballot refused: '.$reason->value);
    }
}
