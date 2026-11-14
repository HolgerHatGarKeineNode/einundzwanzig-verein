<?php

namespace App\Models;

use Database\Factories\DmRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One sending of a message to a recipient list frozen at creation.
 */
class DmRun extends Model
{
    /** @use HasFactory<DmRunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'dm_message_id',
        'recipient_ids',
        'recipient_count',
        'fanned_out_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_ids' => 'array',
            'recipient_count' => 'integer',
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
     * @return HasMany<DmDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(DmDelivery::class);
    }
}
