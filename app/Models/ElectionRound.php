<?php

namespace App\Models;

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionRoundMode;
use App\Enums\ElectionRoundState;
use App\Exceptions\ElectionRoundStateException;
use Carbon\CarbonInterface;
use Database\Factories\ElectionRoundFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ballot question of an election.
 *
 * There is deliberately no relation to marks, ballot rows or receipts on this
 * model. Ballot rows are reachable through `sealedChoices()` only, which
 * refuses until the round is sealed — see there.
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
     * Has `CloseRound` run — is the round closed for good and are its ballot
     * rows rewritten?
     *
     * NOT the same question as "is the window over". A round whose window
     * has run out refuses ballots at once, by the clock; it is sealed only
     * when `CloseRound` has rewritten its ballot rows. Results may key on
     * this and on nothing else.
     */
    public function isSealed(): bool
    {
        return $this->closed_at !== null && $this->annulled_at === null;
    }

    /**
     * The ballot rows of this round — the ONLY sanctioned way to read them,
     * and it refuses until the round is sealed.
     *
     * An interim count is how a candidate with admin rights reads single
     * votes: look at the count after the first ballot. So nothing in the
     * application can count before the close, and after the close the rows
     * no longer sit in the order they were cast in. Asked on a fresh row, not
     * on this instance, so a stale or hand-built model cannot talk its way
     * past the check.
     *
     * @return Builder<ElectionBallotChoice>
     *
     * @throws ElectionRoundStateException
     */
    public function sealedChoices(): Builder
    {
        $current = $this->exists ? self::query()->find($this->getKey()) : null;

        if ($current === null || ! $current->isSealed()) {
            throw new ElectionRoundStateException('The ballots of a round cannot be read before it is sealed.');
        }

        return ElectionBallotChoice::query()->where('election_round_id', $current->getKey());
    }
}
