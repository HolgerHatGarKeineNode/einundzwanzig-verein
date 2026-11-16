<?php

use App\Console\Commands\Dm\DispatchDueDirectMessages;
use App\Console\Commands\Dm\FetchDmInbox;
use App\Console\Commands\Election\CloseEndedRounds;
use App\Console\Commands\Nostr\SyncDmRelays;
use App\Console\Commands\Nostr\SyncProfiles;
use App\Console\Commands\PruneDescriptionImages;
use Illuminate\Support\Facades\Schedule;

// Schedule::command('backup:clean')->daily()->at('01:00');
// Schedule::command('backup:run')->daily()->at('01:30');
Schedule::command(SyncProfiles::class)->daily()->at('00:30');
// Members' DM inbox relays (kind 10050): a list published or changed after the
// last run is otherwise missed, and that member stays on stale relays or no_inbox.
Schedule::command(SyncDmRelays::class)->daily()->at('00:45')->withoutOverlapping(60);
Schedule::command(PruneDescriptionImages::class)->daily()->at('03:00');
// Seals rounds whose window has run out; ballots stop by the clock without it.
Schedule::command(CloseEndedRounds::class)->everyMinute()->withoutOverlapping();
// Starts scheduled direct messages (election timeline, fee reminder) once their moment has come.
Schedule::command(DispatchDueDirectMessages::class)->everyMinute()->withoutOverlapping(5);
// Reads members' replies to the notification key into the board inbox.
Schedule::command(FetchDmInbox::class)->everyFiveMinutes()->withoutOverlapping(10);
