<?php

namespace App\Exceptions;

use App\Models\Election;
use RuntimeException;

/**
 * A membership category may not change right now: a real election is
 * announced and not finished, and its roll rests on the categories
 * (`Election::lockingMemberStatus()`). Carries that election so the caller
 * can name it.
 */
class MemberStatusLockedException extends RuntimeException
{
    public function __construct(public readonly Election $election)
    {
        parent::__construct("Member status is locked by election {$election->getKey()}.");
    }
}
