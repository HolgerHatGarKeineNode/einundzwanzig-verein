<?php

namespace App\Models;

use App\Exceptions\ElectionRoundStateException;
use App\Services\Election\CastingDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The chair's casting vote on the tie of a sealed round, written once by
 * `RecordCastingVote`. The application refuses to update or delete it; an
 * annulled round voids it without touching the row.
 */
class ElectionCastingVote extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'election_round_id',
        'candidate_ids',
        'chair_name',
        'note',
        'recorded_by_pleb_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'candidate_ids' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $refuse = function (): void {
            throw new ElectionRoundStateException('A recorded casting vote cannot be changed.');
        };

        static::updating($refuse);
        static::deleting($refuse);
    }

    /**
     * @return BelongsTo<ElectionRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(ElectionRound::class, 'election_round_id');
    }

    /**
     * @return BelongsTo<EinundzwanzigPleb, $this>
     */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'recorded_by_pleb_id');
    }

    /**
     * The decision for the tie of `$tied` over `$tiedSeats` seats.
     *
     * `RecordCastingVote` stores a list of candidate ids. Anything else (null,
     * a string, an object, nested values) is not read as a decision for
     * nobody, which on a yes = no tie would be a valid „nein“: the decision
     * is marked unreadable and never fits, so the tie stays undecided and
     * `election:verify` reports it.
     *
     * @param  list<int>  $tied
     */
    public function decision(array $tied, int $tiedSeats): CastingDecision
    {
        $stored = $this->candidate_ids;
        $readable = is_array($stored) && array_is_list($stored)
            && array_all($stored, fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)));
        $candidates = $readable ? array_map(fn (int|string $id): int => (int) $id, $stored) : [];
        sort($candidates);

        return new CastingDecision($candidates, $tied, $tiedSeats, (string) $this->chair_name, $this->note, $this->created_at, $readable);
    }
}
