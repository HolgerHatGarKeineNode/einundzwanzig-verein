<?php

use App\Support\DisplayTime;
use Illuminate\Support\Carbon;

it('shows a stored UTC moment in the configured display zone without touching it', function () {
    $stored = Carbon::parse('2026-07-01 10:00:00', 'UTC');

    expect(DisplayTime::zone())->toBe('Europe/Berlin')
        ->and(DisplayTime::of($stored)->format('H:i'))->toBe('12:00')
        ->and($stored->format('H:i e'))->toBe('10:00 UTC');

    config(['app.display_timezone' => 'America/New_York']);

    expect(DisplayTime::of($stored)->format('H:i'))->toBe('06:00');
});
