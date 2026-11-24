<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Times are stored in UTC (app.timezone) and shown in the zone the
 * association meets in (app.display_timezone). The one place that names
 * that zone for display, instead of a literal per page.
 */
final class DisplayTime
{
    public static function zone(): string
    {
        return (string) config('app.display_timezone');
    }

    /**
     * A copy of `$moment` in the display zone; the original is left alone.
     */
    public static function of(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone(self::zone());
    }
}
