<?php

namespace App\Enums;

/**
 * What a round is at a given moment. Never stored — derived by
 * `ElectionRound::stateAt()` from its columns and the clock.
 */
enum ElectionRoundState: string
{
    /** Still editable; nothing is frozen and no roll exists. */
    case Draft = 'draft';

    /** Frozen and its roll written, but the window has not begun. */
    case Scheduled = 'scheduled';

    case Open = 'open';

    case Closed = 'closed';

    case Annulled = 'annulled';
}
