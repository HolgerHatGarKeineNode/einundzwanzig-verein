<?php

namespace App\Models;

use App\Exceptions\ElectionRoundStateException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record of a sealed round, written once by `CloseRound`.
 *
 * `body` is the canonical JSON text built by `RoundManifest`, `hash` its
 * SHA-256. Neither changes after the close; the application refuses to
 * update or delete a manifest.
 */
class ElectionManifest extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'election_round_id',
        'hash',
        'body',
    ];

    protected static function booted(): void
    {
        $refuse = function (): void {
            throw new ElectionRoundStateException('The manifest of a sealed round cannot be changed.');
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
}
