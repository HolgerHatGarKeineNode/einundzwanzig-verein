<?php

namespace App\Models;

use App\Enums\DmAudience;
use Database\Factories\DmRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sending of a message to a recipient list frozen at creation, with the
 * audience it was resolved from (null for a test to one board member).
 */
class DmRun extends Model
{
    /** @use HasFactory<DmRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'dm_message_id',
        'audience',
        'election_event_id',
        'recipient_ids',
        'recipient_count',
        'skipped_ids',
        'fanned_out_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience' => DmAudience::class,
            'recipient_ids' => 'array',
            'recipient_count' => 'integer',
            'skipped_ids' => 'array',
            'fanned_out_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DmMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(DmMessage::class, 'dm_message_id');
    }

    /**
     * The election the audience was resolved against; null when the audience
     * needs none, for a test to a single board member, and for runs from
     * before the column existed.
     *
     * @return BelongsTo<Election, $this>
     */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class, 'election_event_id');
    }

    /**
     * @return HasMany<DmDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(DmDelivery::class);
    }
}
