<?php

namespace App\Enums;

/**
 * Which paid fee entitles a member to vote. Each election names exactly one
 * rule; which one applies is laid down in the election regulations.
 */
enum ElectionFeeRule: string
{
    /** The fee of the election's fee year. */
    case Year = 'year';

    /** The fee of the election's fee year or of the year before it. */
    case YearOrPrevious = 'year_or_previous';

    /** Any fee settled within the twelve months before the roll is written. */
    case LastTwelveMonths = 'last_twelve_months';

    public function needsFeeYear(): bool
    {
        return $this !== self::LastTwelveMonths;
    }
}
