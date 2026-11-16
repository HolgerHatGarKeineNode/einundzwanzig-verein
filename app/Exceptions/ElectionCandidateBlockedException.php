<?php

namespace App\Exceptions;

/**
 * A round cannot be opened because of one of its candidates: elected in
 * another round, level in an undecided tie (of an earlier round, or of the
 * decisive round of any other contest), among the best placed of a round
 * whose run-off is owed, standing in an earlier round that is not sealed yet,
 * or standing in another opened round that is not sealed yet. Or a casting
 * vote cannot be recorded because the chair decided for a candidate elected
 * in another round, or standing in another opened round that is not sealed
 * yet. Names the member and the title of that other round, so
 * the refusal can say who and where.
 */
class ElectionCandidateBlockedException extends ElectionRoundStateException
{
    public function __construct(string $message, public readonly int $memberId, public readonly ?string $roundTitle = null)
    {
        parent::__construct($message);
    }
}
