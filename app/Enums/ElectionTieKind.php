<?php

namespace App\Enums;

/**
 * What a tie is level on. The chair's casting vote (statutes Art. 6) takes a
 * different shape for each.
 */
enum ElectionTieKind: string
{
    /** Candidates level on votes compete for the same seats: the chair picks exactly as many as there are seats. */
    case Candidates = 'candidates';

    /** As many yes as no on a candidate's own confirmation: the chair decides yes or no for each, up to the open seats. */
    case YesEqualsNo = 'yes_equals_no';
}
