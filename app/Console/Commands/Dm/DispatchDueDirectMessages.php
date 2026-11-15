<?php

namespace App\Console\Commands\Dm;

use App\Models\DmSchedule;
use App\Services\Dm\DirectMessagePause;
use App\Services\Dm\StartDirectMessageRun;
use Illuminate\Console\Command;
use Throwable;

/**
 * Start every scheduled message whose moment has come.
 *
 * Idempotent: a started row carries its run and is not selected again, and
 * StartDirectMessageRun::forSchedule() re-checks that under a lock. While
 * the sending is paused nothing is started; the rows stay due and go out on
 * the first run after the pause is lifted. A row that cannot be started is
 * reported and the others are started regardless; the command then exits 1.
 */
class DispatchDueDirectMessages extends Command
{
    protected $signature = 'dm:send-due';

    protected $description = 'Start the scheduled direct messages that are due';

    public function handle(StartDirectMessageRun $start, DirectMessagePause $pause): int
    {
        if ($pause->isPaused()) {
            $this->line('Direct messages are paused; nothing started.');

            return self::SUCCESS;
        }

        $schedules = DmSchedule::query()
            ->due(now())
            ->orderBy('send_at')
            ->orderBy('id')
            ->get();

        $failed = 0;

        foreach ($schedules as $schedule) {
            try {
                $run = $start->forSchedule($schedule);

                if ($run !== null) {
                    $this->line('Started scheduled message '.$schedule->getKey().' as run '.$run->getKey().'.');
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error('Could not start scheduled message '.$schedule->getKey().'.');
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
