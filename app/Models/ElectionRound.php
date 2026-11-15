<?php

namespace App\Models;

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionRoundMode;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use Carbon\CarbonInterface;
use Database\Factories\ElectionRoundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ballot question of an election.
 */
class ElectionRound extends Model
{
    /** @use HasFactory<ElectionRoundFactory> */
    use HasFactory;

    /**
     * Everything that is fixed the moment a round is opened: what is voted
     * on, by which rule, and when. `opened_at` itself is in the list so a
     * round cannot be "un-opened" to get around the others.
     *
     * @var list<string>
     */
    public const FROZEN_ONCE_OPENED = [
        'election_event_id',
        'parent_round_id',
        'position',
        'mode',
        'counting_rule',
        'seats',
        'opens_at',
        'closes_at',
        'reveal_at',
        'opened_at',
    ];

    /**
     * Set once, never changed and never cleared: sealing and annulling are
     * one-way.
     *
     * @var list<string>
     */
    public const ONE_WAY = ['closed_at', 'annulled_at'];

    /** @var list<string> */
    protected $fillable = [
        'election_event_id',
        'parent_round_id',
        'position',
        'title',
        'mode',
        'counting_rule',
        'seats',
        'opens_at',
        'closes_at',
        'reveal_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ElectionRoundMode::class,
            'counting_rule' => ElectionCountingRule::class,
            'seats' => 'integer',
            'position' => 'integer',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'reveal_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'annulled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $round): void {
            if ($round->getOriginal('opened_at') !== null && $round->isDirty(self::FROZEN_ONCE_OPENED)) {
                throw new ElectionRoundStateException('The rule and the window of a round are frozen once it has been opened.');
            }

            foreach (self::ONE_WAY as $attribute) {
                if ($round->getOriginal($attribute) !== null && $round->isDirty($attribute)) {
                    throw new ElectionRoundStateException('Sealing and annulling a round cannot be undone.');
                }
            }
        });

        static::deleting(function (self $round): void {
            if ($round->getOriginal('opened_at') !== null) {
                throw new ElectionRoundStateException('Only a draft round can be deleted.');
            }
        });
    }

    /**
     * @return BelongsTo<Election, $this>
     */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_event_id');
    }

    /**
     * The round this one is the run-off of.
     *
     * @return BelongsTo<ElectionRound, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_round_id');
    }

    /**
     * @return HasMany<ElectionCandidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(ElectionCandidate::class);
    }

    /**
     * @return HasMany<ElectionRollEntry, $this>
     */
    public function rollEntries(): HasMany
    {
        return $this->hasMany(ElectionRollEntry::class);
    }

    /**
     * The signed votes of this round. Counted through `TallyRound`, which
     * refuses until the round is sealed.
     *
     * @return HasMany<ElectionVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(ElectionVote::class);
    }

    /**
     * The id of the event `$pleb` voted with in this round, or null if they
     * have not voted. Only ever shown to that member, as proof their vote
     * arrived; it says nothing about what they chose.
     */
    public function voteEventIdOf(EinundzwanzigPleb $pleb): ?string
    {
        $eventId = $this->votes()->where('einundzwanzig_pleb_id', $pleb->getKey())->value('event_id');

        return is_string($eventId) ? $eventId : null;
    }

    /**
     * What this round is at `$now`.
     *
     * Derived on every read instead of stored, so that no job has to close a
     * round and a job that did not run cannot leave one open. The caller
     * decides which clock and which row: `CastBallot` asks inside its
     * transaction, on the locked row.
     *
     * Every gap falls shut. A round that was opened but has no end — or no
     * beginning — is closed, not open.
     */
    public function stateAt(CarbonInterface $now): ElectionRoundState
    {
        if ($this->annulled_at !== null) {
            return ElectionRoundState::Annulled;
        }

        if ($this->opened_at === null) {
            return ElectionRoundState::Draft;
        }

        if ($this->closed_at !== null || $this->opens_at === null || $this->closes_at === null) {
            return ElectionRoundState::Closed;
        }

        if ($now->lt($this->opens_at)) {
            return ElectionRoundState::Scheduled;
        }

        return $now->lt($this->closes_at) ? ElectionRoundState::Open : ElectionRoundState::Closed;
    }

    /**
     * Has `CloseRound` run — is the round closed for good and its manifest
     * written?
     *
     * NOT the same question as "is the window over". A round whose window
     * has run out refuses votes at once, by the clock; it is sealed only
     * when `CloseRound` has run. Results may key on this and on nothing else.
     */
    public function isSealed(): bool
    {
        return $this->closed_at !== null && $this->annulled_at === null;
    }
}
