<?php

namespace App\Enums;

/**
 * Where a candidacy stands. Only `Approved` puts a member on the ballot.
 */
enum ElectionCandidateStatus: string
{
    /** Submitted by the member, not yet decided. */
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';

    case Withdrawn = 'withdrawn';
}
