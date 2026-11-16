<?php

namespace App\Services\Dm;

use App\Enums\DmAudience;
use App\Jobs\Dm\DispatchBulkDirectMessages;
use App\Models\DmMessage;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
    /** The cache lock that serialises the repeat-lock check with the creation of the run. */
    public const string START_LOCK = 'dm:start-run';

    /** Longer than a start takes; a start whose process died frees the lock after this. */
    private const int START_LOCK_SECONDS = 60;

    private const int START_LOCK_WAIT_SECONDS = 15;

    public function __construct(
        private readonly DirectMessageAudience $audience,
        private readonly DirectMessagePause $pause,
        private readonly DirectMessageRepeatLock $repeatLock,
    ) {}

    /**
     * Whoever got the same message for the same audience within the repeat
     * lock (DirectMessageRepeatLock) is left out and stored as skipped, so a
     * second click creates no second delivery. Scheduled rows are not locked:
     * a plan may send the same reminder twice on purpose.
     *
     * The check and the creation of the run hold START_LOCK, so two board
     * members confirming at once cannot both pass the check before either
     * run exists: the second one waits, then finds the first run. When the
     * lock is not free within START_LOCK_WAIT_SECONDS nothing is written.
     *
     * @param  list<int>|null  $confirmedRecipientIds  the members the board confirmed (DirectMessageDryRunResult::$recipientIds);
     *                                                 when given, the start freezes exactly these, less the
     *                                                 ones the repeat lock skips now, or nothing
     *
     * @throws DirectMessageAudienceChanged when the audience resolved now differs from the confirmed one by more than
     *                                      the members the repeat lock skips; nothing is written
     * @throws LockTimeoutException when another start holds the lock for longer than START_LOCK_WAIT_SECONDS; nothing is written
     */
    public function handle(DmMessage $message, DmAudience $audience, ?Election $election = null, ?array $confirmedRecipientIds = null): ?DmRun
    {
        if ($this->pause->isPaused()) {
            return null;
        }

        $run = Cache::lock(self::START_LOCK, self::START_LOCK_SECONDS)->block(
            self::START_LOCK_WAIT_SECONDS,
            fn (): DmRun => $this->freeze($message, $audience, $election, $confirmedRecipientIds, lockRepeats: true),
        );

        DispatchBulkDirectMessages::dispatch($run->id);

        return $run;
    }

    /**
     * Send a message to exactly one member: the board member who writes it,
     * to read it in their own client before it goes to many. The run stores
     * no audience; that is what marks it as such a test.
     */
    public function toSelf(DmMessage $message, EinundzwanzigPleb $boardMember): ?DmRun
    {
        if ($this->pause->isPaused()) {
            return null;
        }

        $run = $message->freezeRun([$boardMember]);

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

    /**
     * @param  list<int>|null  $confirmedRecipientIds
     */
    private function freeze(DmMessage $message, DmAudience $audience, ?Election $election, ?array $confirmedRecipientIds = null, bool $lockRepeats = false): DmRun
    {
        $recipients = $this->audience->query($audience, $election)->select('einundzwanzig_plebs.id')->get();
        $skippedIds = $lockRepeats ? $this->repeatLock->skippedIds(
            $message->type,
            (string) $message->plaintext,
            $message->variables ?? [],
            $audience,
            $election,
            $recipients->map(fn (EinundzwanzigPleb $recipient): int => (int) $recipient->getKey())->all(),
            now(),
        ) : [];
        $recipients = $recipients->reject(fn (EinundzwanzigPleb $recipient): bool => in_array((int) $recipient->getKey(), $skippedIds, true))->values();

        if ($confirmedRecipientIds !== null) {
            $current = $recipients->map(fn (EinundzwanzigPleb $recipient): int => (int) $recipient->getKey())->unique()->sort()->values()->all();
            $confirmed = collect($confirmedRecipientIds)->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

            // Members who got this message since the dry run (a second board page confirmed first)
            // are a skip, not a change of the audience: the rest goes out, nobody gets it twice.
            $justSent = array_diff($confirmed, $current);
            $onlyRepeats = array_diff($current, $confirmed) === [] && array_diff($justSent, $skippedIds) === [];

            if ($current !== $confirmed && ! $onlyRepeats) {
                throw new DirectMessageAudienceChanged(count($confirmed), count($current));
            }
        }

        return $message->freezeRun(
            $recipients,
            $audience,
            $audience->needsElection() ? $election : null,
            $skippedIds,
        );
    }
}
