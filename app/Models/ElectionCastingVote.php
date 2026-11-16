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
     * @param  list<int>  $tied
     */
    public function decision(array $tied, int $tiedSeats): CastingDecision
    {
        $candidates = array_map(fn (mixed $id): int => (int) $id, is_array($this->candidate_ids) ? array_values($this->candidate_ids) : []);
        sort($candidates);

        return new CastingDecision($candidates, $tied, $tiedSeats, (string) $this->chair_name, $this->note, $this->created_at);
    }
}
