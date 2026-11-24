<?php

namespace App\Enums;

/**
 * Where an election stands as a whole. Never stored — derived by
 * `Election::phase()` from its announcement and its rounds.
 */
enum ElectionPhase: string
{
    /** Not announced yet; only the board knows it. */
    case Draft = 'draft';

    /** Announced, but no office (round) set up yet. */
    case Announced = 'announced';

    /** Offices set up, none opened: members may stand until the meeting. */
    case Candidacy = 'candidacy';

    /** A round has been opened and not every round is finished. */
    case Meeting = 'meeting';

    /** A round has been opened and every round is closed or annulled. */
    case Result = 'result';

    /**
     * The position on the run sheet, 0 (draft) to 4 (result).
     */
    public function step(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Announced => 1,
            self::Candidacy => 2,
            self::Meeting => 3,
            self::Result => 4,
        };
    }
}
