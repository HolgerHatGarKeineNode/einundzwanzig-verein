<?php

namespace App\Services\Dm;

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Models\DmRun;
use App\Models\Election;
use Carbon\CarbonInterface;

/**
 * A second sending of the same message to the same audience within the lock
 * period reaches nobody twice: whoever was a recipient of such a run is left
 * out and counted as skipped (design Q23 — "zweiter Versand in der Sperrfrist
 * legt 0 neue an").
 *
 * "The same message" is the same type, text and placeholder values; "the
 * same audience" is the same named audience and election. A test to a single
 * board member stores no audience and never counts. The period is
 * `einundzwanzig.config.dm_repeat_lock_hours` (24 by default); 0 switches
 * the lock off.
 */
final class DirectMessageRepeatLock
{
    /**
     * The members of `$candidateIds` who got this message for this audience
     * within the lock period before `$now`, ascending.
     *
     * @param  array<string, string>  $variables
     * @param  list<int>  $candidateIds
     * @return list<int>
     */
    public function skippedIds(
        DmMessageType $type,
        string $plaintext,
        array $variables,
        DmAudience $audience,
        ?Election $election,
        array $candidateIds,
        CarbonInterface $now,
    ): array {
        $hours = $this->hours();

        if ($hours <= 0 || $candidateIds === []) {
            return [];
        }

        $runs = DmRun::query()
            ->with('message')
            ->where('audience', $audience->value)
            ->where('election_event_id', $audience->needsElection() ? $election?->getKey() : null)
            ->where('created_at', '>=', $now->copy()->subHours($hours))
            ->whereHas('message', fn ($messages) => $messages->where('type', $type->value)->where('plaintext', $plaintext))
            ->get()
            ->filter(fn (DmRun $run): bool => $this->sameValues($run->message?->variables ?? [], $variables));

        $earlier = $runs->flatMap(fn (DmRun $run): array => array_map('intval', $run->recipient_ids ?? []))->unique()->all();

        $skipped = array_values(array_intersect(array_map('intval', $candidateIds), $earlier));
        sort($skipped);

        return $skipped;
    }

    public function hours(): int
    {
        return (int) config('einundzwanzig.config.dm_repeat_lock_hours', 24);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, string>  $given
     */
    private function sameValues(array $stored, array $given): bool
    {
        ksort($stored);
        ksort($given);

        return array_map('strval', $stored) === array_map('strval', $given);
    }
}
