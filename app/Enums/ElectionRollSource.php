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
}
