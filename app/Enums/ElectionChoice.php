<?php

namespace App\Enums;

/**
 * What a single ballot row says.
 */
enum ElectionChoice: string
{
    /** A vote for the candidate, in rounds where candidates are picked. */
    case For = 'for';

    case Yes = 'yes';

    case No = 'no';

    /** An empty ballot. The only choice without a candidate. */
    case Blank = 'blank';
}
