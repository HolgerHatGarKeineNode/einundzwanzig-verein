<?php

namespace App\Enums;

/**
 * How a row got onto the electoral roll.
 */
enum ElectionRollSource: string
{
    /** Part of the snapshot written when the round opened. */
    case Opening = 'opening';

    /** Enrolled by a fee payment that arrived after the round opened. */
    case LatePayment = 'late_payment';

    /** Copied from the round this run-off belongs to. */
    case Inherited = 'inherited';

    /**
     * Entitled through a category change the Wahlleitung made under the
     * status lock (`ChangeMemberStatus`) — enrolled at the opening, or by a
     * payment inside the window after the change. Never `LatePayment`, so
     * the roll shows which entries rest on a decision taken during the
     * election.
     */
    case StatusChange = 'status_change';

    /** Entered by hand by the Wahlleitung (`EnrolByHand`). */
    case ByHand = 'by_hand';
}
