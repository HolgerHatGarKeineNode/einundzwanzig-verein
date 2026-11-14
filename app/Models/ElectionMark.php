<?php

namespace App\Models;

use Database\Factories\ElectionMarkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * "This member has voted in this round" — the row's existence is the whole
 * statement.
 *
 * No key of its own, no timestamps, no relation to a ballot or a receipt, and
 * never `HasUuids`/`HasUlids`: Laravel's generated ids are time-ordered, and
 * the time of a mark next to a name is the link to the ballot.
 * `tests/Feature/Models/BallotSecrecyTest.php` holds this in place.
 */
class ElectionMark extends Model
{
    /** @use HasFactory<ElectionMarkFactory> */
    use HasFactory;

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /** @var list<string> */
    protected $fillable = [
        'election_round_id',
        'einundzwanzig_pleb_id',
    ];
}
