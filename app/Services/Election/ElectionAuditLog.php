<?php

namespace App\Services\Election;

use App\Enums\ElectionAuditAction;
use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionAuditEntry;
use App\Models\ElectionRound;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The append-only, hash-chained audit log of an election.
 *
 * Every line hashes its own content together with the hash of the line
 * before it, so changing, removing or reordering a line breaks every hash
 * from there on. One chain per election; the first line chains to
 * `GENESIS`.
 *
 * What a line may carry is closed: an action, the round, up to three members
 * (who acted, the second person, whom it concerns) and `details` from
 * `DETAIL_KEYS`. There is no way to put a ballot choice, a ballot id or a
 * receipt into it — the paper vote line names the member who voted on paper
 * and nothing about the vote.
 */
class ElectionAuditLog
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * The only keys `details` takes, each with the type it must have.
     *
     * @var array<string, 'string'|'int'>
     */
    public const DETAIL_KEYS = [
        'reason' => 'string',
        'from' => 'string',
        'to' => 'string',
        'source' => 'string',
        'candidate_id' => 'int',
    ];

    public const MAX_REASON_LENGTH = 1000;

    /**
     * Append one line. Runs in the caller's transaction if there is one, and
     * takes a lock on the election row so two lines can never claim the same
     * place in the chain.
     *
     * @param  array<string, string|int>  $details
     */
    public function append(
        Election $election,
        ElectionAuditAction $action,
        ?EinundzwanzigPleb $actor,
        ?ElectionRound $round = null,
        ?EinundzwanzigPleb $subject = null,
        ?EinundzwanzigPleb $second = null,
        array $details = [],
    ): ElectionAuditEntry {
        $details = $this->checkedDetails($details);

        return DB::transaction(function () use ($election, $action, $actor, $round, $subject, $second, $details): ElectionAuditEntry {
            Election::query()->lockForUpdate()->whereKey($election->getKey())->first();

            $last = ElectionAuditEntry::query()
                ->where('election_event_id', $election->getKey())
                ->orderByDesc('sequence')
                ->first();

            $attributes = [
                'election_event_id' => (int) $election->getKey(),
                'sequence' => ($last?->sequence ?? 0) + 1,
                'action' => $action->value,
                'election_round_id' => $round?->getKey() === null ? null : (int) $round->getKey(),
                'actor_pleb_id' => $actor?->getKey() === null ? null : (int) $actor->getKey(),
                'second_pleb_id' => $second?->getKey() === null ? null : (int) $second->getKey(),
                'subject_pleb_id' => $subject?->getKey() === null ? null : (int) $subject->getKey(),
                'details' => $details === [] ? null : $details,
                'previous_hash' => $last?->hash ?? self::GENESIS,
                'created_at' => now()->startOfSecond(),
            ];

            return ElectionAuditEntry::query()->create([
                ...$attributes,
                'hash' => $this->hashOf($attributes),
            ]);
        });
    }

    /**
     * Does the chain of `$election` hold — numbered 1, 2, 3 … without a gap,
     * every line chained to the one before and hashing to its stored hash?
     *
     * An election without lines holds.
     */
    public function verify(Election $election): bool
    {
        $previous = self::GENESIS;
        $expected = 1;

        foreach (ElectionAuditEntry::query()->where('election_event_id', $election->getKey())->orderBy('sequence')->cursor() as $entry) {
            $attributes = $this->attributesOf($entry);

            if ($attributes['sequence'] !== $expected
                || ! hash_equals($previous, (string) $entry->previous_hash)
                || ! hash_equals($this->hashOf($attributes), (string) $entry->hash)) {
                return false;
            }

            $previous = (string) $entry->hash;
            $expected++;
        }

        return true;
    }

    /**
     * The hash of the last line of the chain, or `GENESIS` if there is none.
     */
    public function headHash(Election $election): string
    {
        return ElectionAuditEntry::query()
            ->where('election_event_id', $election->getKey())
            ->orderByDesc('sequence')
            ->value('hash') ?? self::GENESIS;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, string|int>
     */
    protected function checkedDetails(array $details): array
    {
        foreach ($details as $key => $value) {
            $type = self::DETAIL_KEYS[$key] ?? null;

            if ($type === null || ($type === 'string' && ! is_string($value)) || ($type === 'int' && ! is_int($value))) {
                throw new InvalidArgumentException('An audit line does not take the detail ['.$key.'].');
            }
        }

        if (isset($details['reason']) && (trim($details['reason']) === '' || mb_strlen($details['reason']) > self::MAX_REASON_LENGTH)) {
            throw new InvalidArgumentException('An audit reason must be given and at most '.self::MAX_REASON_LENGTH.' characters long.');
        }

        ksort($details);

        return $details;
    }

    /**
     * The hashed content of a stored line, read back into the exact shape
     * `append()` hashed.
     *
     * @return array<string, mixed>
     */
    protected function attributesOf(ElectionAuditEntry $entry): array
    {
        $raw = $entry->getAttributes();
        $details = $entry->details;

        if (is_array($details)) {
            ksort($details);
        }

        return [
            'election_event_id' => (int) $raw['election_event_id'],
            'sequence' => (int) $raw['sequence'],
            'action' => (string) $raw['action'],
            'election_round_id' => $raw['election_round_id'] === null ? null : (int) $raw['election_round_id'],
            'actor_pleb_id' => $raw['actor_pleb_id'] === null ? null : (int) $raw['actor_pleb_id'],
            'second_pleb_id' => $raw['second_pleb_id'] === null ? null : (int) $raw['second_pleb_id'],
            'subject_pleb_id' => $raw['subject_pleb_id'] === null ? null : (int) $raw['subject_pleb_id'],
            'details' => $details === [] ? null : $details,
            'previous_hash' => (string) $raw['previous_hash'],
            'created_at' => $entry->created_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function hashOf(array $attributes): string
    {
        $createdAt = $attributes['created_at'];

        $canonical = [
            ...$attributes,
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->copy()->utc()->format('Y-m-d\TH:i:s\Z') : null,
        ];

        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
