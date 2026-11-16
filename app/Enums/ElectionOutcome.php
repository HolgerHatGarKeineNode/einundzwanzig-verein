<?php

namespace App\Enums;

/**
 * What the count of a sealed round decided.
 *
 * The application never breaks a tie itself: a tie stays a tie until the
 * election officer enters the chair's casting decision (statutes Art. 6).
 */
enum ElectionOutcome: string
{
    /** Every seat is filled. */
    case Complete = 'complete';

    /** Candidates level on votes compete for the last seats; nobody among them is elected yet. */
    case Tie = 'tie';

    /** Fewer candidates were elected than the round has seats, and no tie could fill the rest. */
    case Understaffed = 'understaffed';
}
