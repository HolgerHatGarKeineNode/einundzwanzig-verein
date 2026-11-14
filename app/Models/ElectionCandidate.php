<?php

namespace App\Models;

use App\Exceptions\ElectionRoundStateException;
use Database\Factories\ElectionCandidateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member standing in a round.
 */
class ElectionCandidate extends Model
{
    /** @use HasFactory<ElectionCandidateFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'election_round_id',
        'einundzwanzig_pleb_id',
    ];

    /**
     * The list of candidates is part of what a round freezes when it opens:
     * the ballot is validated against it.
     */
    protected static function booted(): void
    {
        $refuseOnceOpened = function (self $candidate): void {
            $roundIds = array_filter([$candidate->election_round_id, $candidate->getOriginal('election_round_id')]);

            if (ElectionRound::query()->whereKey($roundIds)->whereNotNull('opened_at')->exists()) {
                throw new ElectionRoundStateException('The candidates of a round are frozen once it has been opened.');
            }
        };

        static::creating($refuseOnceOpened);
        static::updating($refuseOnceOpened);
        static::deleting($refuseOnceOpened);
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
    public function pleb(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'einundzwanzig_pleb_id');
    }
}
