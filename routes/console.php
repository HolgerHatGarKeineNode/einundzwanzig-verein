<?php

use App\Console\Commands\Nostr\SyncProfiles;
use App\Console\Commands\PruneDescriptionImages;
use Illuminate\Support\Facades\Schedule;

// Schedule::command('backup:clean')->daily()->at('01:00');
// Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command(SyncProfiles::class)->daily()->at('00:30');
Schedule::command(PruneDescriptionImages::class)->daily()->at('03:00');
