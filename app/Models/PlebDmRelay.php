<?php

namespace App\Models;

use Database\Factories\PlebDmRelayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's DM inbox relays from their newest kind 10050 list (NIP-17).
 *
 * Written only by `nostr:sync-dm-relays`; see the migration for why a member
 * without a row receives no direct message.
 */
class PlebDmRelay extends Model
{
    /** @use HasFactory<PlebDmRelayFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'einundzwanzig_pleb_id',
        'relays',
        'event_id',
        'event_created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relays' => 'array',
            'event_created_at' => 'integer',
        ];
    }

    public function pleb(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'einundzwanzig_pleb_id');
    }
}
