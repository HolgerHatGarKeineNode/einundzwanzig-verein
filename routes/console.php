<?php

use App\Console\Commands\Dm\DispatchDueDirectMessages;
use App\Console\Commands\Election\CloseEndedRounds;
use App\Console\Commands\Nostr\SyncProfiles;
use App\Console\Commands\PruneDescriptionImages;
use Illuminate\Support\Facades\Schedule;

// Schedule::command('backup:clean')->daily()->at('01:00');
// Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command(SyncProfiles::class)->daily()->at('00:30');
Schedule::command(PruneDescriptionImages::class)->daily()->at('03:00');
// Seals rounds whose window has run out; ballots stop by the clock without it.
Schedule::command(CloseEndedRounds::class)->everyMinute()->withoutOverlapping();
// Starts scheduled direct messages (election timeline, fee reminder) once their moment has come.
Schedule::command(DispatchDueDirectMessages::class)->everyMinute()->withoutOverlapping(5);
