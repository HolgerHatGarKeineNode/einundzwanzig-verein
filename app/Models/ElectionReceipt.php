<?php

namespace App\Models;

use Database\Factories\ElectionReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Proof that a ballot was taken in. Says nothing about its content: the row
 * references neither a member nor a ballot.
 *
 * No key of its own, no timestamps, never `HasUuids`/`HasUlids`.
 * `tests/Feature/Models/BallotSecrecyTest.php` holds this in place.
 */
class ElectionReceipt extends Model
{
    /** @use HasFactory<ElectionReceiptFactory> */
    use HasFactory;

    /**
     * What a receipt code looks like: 128 bits made up by the voter's
     * browser, as 32 lowercase hex characters. `/D` so that `$` does not
     * match before a trailing newline.
     */
    public const CODE_PATTERN = '/^[0-9a-f]{32}$/D';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /** @var list<string> */
    protected $fillable = [
        'election_round_id',
        'hash',
    ];

    /**
     * The only thing stored about a receipt code.
     *
     * The round is part of the hashed text as well as a column, so the same
     * code used in two rounds does not produce the same hash twice. The
     * browser computes the identical value to find its receipt in the
     * published list.
     */
    public static function hashFor(int $roundId, string $code): string
    {
        return hash('sha256', 'einundzwanzig-election-receipt:'.$roundId.':'.$code);
    }
}
