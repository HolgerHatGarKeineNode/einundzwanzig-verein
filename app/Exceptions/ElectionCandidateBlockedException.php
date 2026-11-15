<?php

namespace App\Exceptions;

/**
 * A round cannot be opened because of one of its candidates: elected in
 * another round, level in an undecided tie, or standing in an earlier round
 * that is not sealed yet. Names the member, so the refusal can say who.
 */
class ElectionCandidateBlockedException extends ElectionRoundStateException
{
    public function __construct(string $message, public readonly int $memberId)
    {
        parent::__construct($message);
    }
}
