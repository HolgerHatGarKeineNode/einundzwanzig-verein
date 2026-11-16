<?php

namespace App\Models;

use App\Enums\ElectionRollReason;
use App\Enums\ElectionRollSource;
use Database\Factories\ElectionRollEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's right to vote in one round. Written by `ElectoralRoll`, never
 * removed.
 */
class ElectionRollEntry extends Model
{
    /** @use HasFactory<ElectionRollEntryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'election_round_id',
        'einundzwanzig_pleb_id',
        'reason',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => ElectionRollReason::class,
            'source' => ElectionRollSource::class,
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
