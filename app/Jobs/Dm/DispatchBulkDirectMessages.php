<?php

namespace App\Jobs\Dm;

use App\Enums\DmDeliveryStatus;
use App\Models\DmDelivery;
use App\Models\DmRun;
use App\Models\EinundzwanzigPleb;
use App\Services\Dm\DirectMessagePause;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns a run's frozen recipient list into deliveries and queues one
 * SendDirectMessage per delivery that still has work to do.
 *
 * Safe to run any number of times for the same run, which is how a fan-out
 * that broke halfway is resumed:
 *
 *  - Deliveries are inserted with "ignore on conflict" against the unique
 *    index on (run, member): an existing row — whatever its state — is never
 *    touched, so `sent` never goes back to `pending` and nobody gets a second
 *    row.
 *  - Only `pending`, `failed` and expired `claimed` deliveries are queued
 *    again (an expired claim is a worker that died). A `failed` one
 *    republishes its stored wrap to the relays that have not taken it (see
 *    SendDirectMessage), so resuming adds no second message.
 *
 * Does nothing while direct messages are paused (DirectMessagePause).
 */
class DispatchBulkDirectMessages implements ShouldQueue
{
    use Queueable;

    /** Database work only; no relay is dialled from here. */
    public int $timeout = 60;

    private const int INSERT_CHUNK = 500;

    public function __construct(public int $runId) {}

    public function handle(): void
    {
        // Paused: nothing is created and nothing queued. Lifting the pause
        // (DirectMessagePause::resume()) dispatches this job again.
        if (app(DirectMessagePause::class)->isPaused()) {
            return;
        }

        $run = DmRun::query()->findOrFail($this->runId);
        $now = now();

        // A member row is never deleted (the erasure anonymises it), but a
        // missing one must not break the whole run on the foreign key.
        $recipientIds = EinundzwanzigPleb::query()
            ->whereKey($run->recipient_ids)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $rows = array_map(fn (int $recipientId): array => [
            'dm_run_id' => $run->id,
            'einundzwanzig_pleb_id' => $recipientId,
            'status' => DmDeliveryStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ], $recipientIds);

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DmDelivery::query()->insertOrIgnore($chunk);
        }

        if ($run->fanned_out_at === null) {
            $run->forceFill(['fanned_out_at' => $now])->save();
        }

        // A pause that came while the rows were written stops the queueing.
        if (app(DirectMessagePause::class)->isPaused()) {
            return;
        }

        DmDelivery::query()
            ->where('dm_run_id', $run->id)
            ->withWorkLeft($now)
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $deliveryId) => SendDirectMessage::dispatch($deliveryId));
    }
}
