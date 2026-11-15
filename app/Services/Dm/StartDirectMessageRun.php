<?php

namespace App\Services\Dm;

use App\Enums\DmAudience;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\Election;
use Illuminate\Support\Facades\DB;

/**
 * Starts a sending: resolves the audience once, freezes the result on the
 * run and hands the run to the fan-out.
 *
 * The frozen list is the whole truth about who gets this run. The fan-out
 * reads `recipient_ids` and never the audience, so a member who joins or
 * pays after the start is not added, and nobody drops out.
 *
 * Nothing starts while the sending is paused (DirectMessagePause); both
 * methods then return null and write nothing.
 */
final class StartDirectMessageRun
{
    public function __construct(
        private readonly DirectMessageAudience $audience,
        private readonly DirectMessagePause $pause,
    ) {}

    public function handle(DmMessage $message, DmAudience $audience, ?Election $election = null): ?DmRun
    {
        if ($this->pause->isPaused()) {
            return null;
        }

        $run = $this->freeze($message, $audience, $election);

        DispatchBulkDirectMessages::dispatch($run->id);

        return $run;
    }

    /**
     * Start a scheduled row, at most once: the row is re-read under a lock
     * and marked with its run in the same transaction that creates the run.
     * Null when the row was started meanwhile or the sending is paused.
     */
    public function forSchedule(DmSchedule $schedule): ?DmRun
    {
        if ($this->pause->isPaused()) {
            return null;
        }

        $run = DB::transaction(function () use ($schedule): ?DmRun {
            $schedule = DmSchedule::query()
                ->with(['message', 'election'])
                ->whereKey($schedule->getKey())
                ->whereNull('dm_run_id')
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                return null;
            }

            $run = $this->freeze($schedule->message, $schedule->audience, $schedule->election);
            $schedule->forceFill(['dm_run_id' => $run->id])->save();

            return $run;
        });

        if ($run !== null) {
            DispatchBulkDirectMessages::dispatch($run->id);
        }

        return $run;
    }

    private function freeze(DmMessage $message, DmAudience $audience, ?Election $election): DmRun
    {
        return $message->freezeRun(
            $this->audience->query($audience, $election)->select('einundzwanzig_plebs.id')->get(),
        );
    }
}
