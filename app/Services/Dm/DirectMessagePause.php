<?php

namespace App\Services\Dm;

use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The board's global stop for direct messages.
 *
 * While paused, no run is started (StartDirectMessageRun, `dm:send-due`),
 * the fan-out queues nothing, and a send that was already queued leaves its
 * delivery `pending` instead of publishing. Resuming hands every run with
 * work left back to the fan-out, which is safe to repeat.
 *
 * Kept in the database, not in the cache: a cleared cache must not lift a
 * pause. A database that cannot be read throws, and nothing is sent.
 */
final class DirectMessagePause
{
    private const string TABLE = 'dm_settings';

    public function isPaused(): bool
    {
        return DB::table(self::TABLE)->whereNotNull('paused_at')->exists();
    }

    /**
     * Since when the sending is paused; null while it is not.
     */
    public function pausedAt(): ?CarbonInterface
    {
        $pausedAt = DB::table(self::TABLE)->whereNotNull('paused_at')->value('paused_at');

        return $pausedAt === null ? null : Carbon::parse($pausedAt);
    }

    public function pause(): void
    {
        if ($this->isPaused()) {
            return;
        }

        $now = now();

        DB::table(self::TABLE)->updateOrInsert(['id' => 1], [
            'paused_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Lift the pause and queue the fan-out again for every run that has not
     * been fanned out or still has work left by the fan-out's own definition
     * (DmDelivery::scopeWithWorkLeft): a send swallowed by the pause may have
     * been the retry of a `failed` delivery or the redelivery of an expired
     * claim, not only a `pending` one.
     */
    public function resume(): void
    {
        $now = now();

        DB::table(self::TABLE)->update(['paused_at' => null, 'updated_at' => $now]);

        DmRun::query()
            ->where(fn ($query) => $query
                ->whereNull('fanned_out_at')
                ->orWhereHas('deliveries', fn ($deliveries) => $deliveries->withWorkLeft($now)))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $runId) => DispatchBulkDirectMessages::dispatch($runId));
    }
}
