<?php

namespace App\Console\Commands\Election;

use App\Models\ElectionRound;
use App\Services\Election\CloseRound;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seal every round whose window has run out.
 *
 * Ballots stop at the end of the window by the clock alone (see
 * `ElectionRound::stateAt()`); nothing waits for this command to refuse
 * them. What it adds is the seal — the rewrite and the manifest — so that
 * the result can be read without somebody having to press a button. A live
 * round falls under it once its maximum duration is over.
 *
 * Idempotent: a sealed round is not selected again, and `CloseRound` leaves
 * a sealed round alone anyway. A round that cannot be sealed is reported and
 * the others are sealed regardless; the command then exits 1.
 */
class CloseEndedRounds extends Command
{
    protected $signature = 'election:close-ended';

    protected $description = 'Seal every election round whose window has run out';

    public function handle(CloseRound $closeRound): int
    {
        $rounds = ElectionRound::query()
            ->whereNotNull('opened_at')
            ->whereNull('closed_at')
            ->whereNull('annulled_at')
            ->where('closes_at', '<=', now())
            ->orderBy('closes_at')
            ->orderBy('id')
            ->get();

        $failed = 0;

        foreach ($rounds as $round) {
            try {
                $closeRound->handle($round);
                $this->line('Sealed round '.$round->getKey().'.');
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->error('Could not seal round '.$round->getKey().'.');
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
