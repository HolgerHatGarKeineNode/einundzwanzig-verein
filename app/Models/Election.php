<?php

namespace App\Models;

use App\Enums\ElectionFeeRule;
use App\Exceptions\ElectionRoundStateException;
use Database\Factories\ElectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An election of the association and the fee rule of its electoral roll.
 */
class Election extends Model
{
    /** @use HasFactory<ElectionFactory> */
    use HasFactory;

    /**
     * What decides who is on the roll. Frozen once a round of this election
     * has been opened — a rule changed afterwards would make two rounds of
     * the same election answer "who may vote" differently.
     *
     * @var list<string>
     */
    public const ROLL_RULE_ATTRIBUTES = ['fee_rule', 'fee_year', 'honorary_without_fee'];

    /**
     * Not `elections`: the migration that dropped the old table recreates it
     * under that name on rollback.
     */
    protected $table = 'election_events';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'meeting_at',
        'call_url',
        'fee_rule',
        'fee_year',
        'honorary_without_fee',
        'is_test',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_rule' => ElectionFeeRule::class,
            'fee_year' => 'integer',
            'honorary_without_fee' => 'boolean',
            'is_test' => 'boolean',
            'announced_at' => 'datetime',
            'meeting_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $election): void {
            /*
             * Fixed at creation: `election:purge-test` deletes every row of a
             * test election, so a real election must never become one — and
             * a trial must not turn into the real thing either.
             */
            if ($election->isDirty('is_test')) {
                throw new ElectionRoundStateException('Whether an election is a test is fixed when it is created.');
            }

            if ($election->isDirty(self::ROLL_RULE_ATTRIBUTES) && $election->rounds()->whereNotNull('opened_at')->exists()) {
                throw new ElectionRoundStateException('The fee rule of an election is frozen once one of its rounds has been opened.');
            }
        });

        /*
         * An election goes only as long as all of its rounds are drafts. The
         * rounds are deleted one by one through the model, run-offs before
         * the round they belong to, so each passes its own draft-only guard;
         * `election_rounds.election_event_id` restricts deletion in the
         * database, so no cascade can take an opened round along with it.
         */
        static::deleting(function (self $election): void {
            if ($election->rounds()->whereNotNull('opened_at')->exists()) {
                throw new ElectionRoundStateException('An election can only be deleted while all of its rounds are drafts.');
            }

            while (($rounds = $election->rounds()->get())->isNotEmpty()) {
                $parents = $rounds->pluck('parent_round_id')->filter()->map(fn (mixed $id): int => (int) $id)->all();
                $leaves = $rounds->reject(fn (ElectionRound $round): bool => in_array((int) $round->getKey(), $parents, true));

                if ($leaves->isEmpty()) {
                    throw new ElectionRoundStateException('The rounds of this election refer to each other in a circle.');
                }

                $leaves->each(fn (ElectionRound $round): ?bool => $round->delete());
            }
        });
    }

    /**
     * @return HasMany<ElectionRound, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(ElectionRound::class, 'election_event_id')->orderBy('position');
    }
}
