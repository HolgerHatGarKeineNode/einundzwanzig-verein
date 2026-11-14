<?php

namespace App\Models;

use App\Services\Nostr\RelayPublishStatus;
use Database\Factories\DmDeliveryRelayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The latest answer of one inbox relay to one delivery's wrap.
 */
class DmDeliveryRelay extends Model
{
    /** @use HasFactory<DmDeliveryRelayFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'dm_delivery_id',
        'relay_url',
        'status',
        'message',
        'attempts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RelayPublishStatus::class,
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DmDelivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(DmDelivery::class, 'dm_delivery_id');
    }
}
