<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's signed vote in one round.
 *
 * Who voted how is known to whoever reads the database — the board — and to
 * nobody else: no page shows a vote next to a member, only counts
 * (`tests/Feature/ElectionVoteVisibilityTest.php`). Written by `CastBallot`.
 */
class ElectionVote extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'election_round_id',
        'einundzwanzig_pleb_id',
        'pubkey',
        'event_id',
        'event',
        'choices',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => 'array',
            'choices' => 'array',
        ];
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
