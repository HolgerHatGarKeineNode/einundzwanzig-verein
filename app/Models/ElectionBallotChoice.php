<?php

namespace App\Models;

use App\Enums\ElectionChoice;
use Database\Factories\ElectionBallotChoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One chosen option of one ballot.
 *
 * Rows of the same ballot share nothing. The id is random, there are no
 * timestamps and there is no relation method on this model — not to a member,
 * not to a mark, not to a receipt, and not to its sibling rows.
 *
 * Never `HasUuids`, `HasUlids` or `HasVersion4Uuids`: all three generate
 * time-ordered ids (`HasUuids` is UUIDv7, the wall clock in its first 48
 * bits), which would write the moment of the cast into every row.
 * `tests/Feature/Models/BallotSecrecyTest.php` holds this in place.
 *
 * Read through `ElectionRound::sealedChoices()`, which refuses before the
 * round is sealed.
 */
class ElectionBallotChoice extends Model
{
    /** @use HasFactory<ElectionBallotChoiceFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'election_round_id',
        'election_candidate_id',
        'choice',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'choice' => ElectionChoice::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $choice): void {
            $choice->id ??= self::newId();
        });
    }

    /**
     * 128 bits from the system's CSPRNG, hex encoded. Carries no time and no
     * order.
     */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
