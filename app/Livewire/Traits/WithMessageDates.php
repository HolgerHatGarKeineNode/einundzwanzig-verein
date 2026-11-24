<?php

namespace App\Livewire\Traits;

use App\Support\DisplayTime;
use Carbon\CarbonInterface;

/**
 * How the board's message centre writes a moment: in German time, long in
 * sentences and schedule rows, short in the history list. Shared by the
 * page and its tabs, so a date reads the same on each of them.
 */
trait WithMessageDates
{
    public function longDate(CarbonInterface $moment): string
    {
        $local = DisplayTime::of($moment)->locale('de');

        return $local->isoFormat('dd, D. MMMM YYYY').', '.$local->format('H:i').' '.__('Uhr');
    }

    public function shortDate(CarbonInterface $moment): string
    {
        $local = DisplayTime::of($moment)->locale('de');

        return $local->isoFormat('D. MMM YYYY').', '.$local->format('H:i');
    }
}
