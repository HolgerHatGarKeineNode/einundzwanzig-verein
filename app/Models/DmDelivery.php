<?php

namespace App\Models;

use App\Enums\DmDeliveryStatus;
use Database\Factories\DmDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One recipient of one run: the text they get, the wrap that carries it and
 * how far it came. Written by DispatchBulkDirectMessages and
 * SendDirectMessage only.
 */
class DmDelivery extends Model
{
    /** @use HasFactory<DmDeliveryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'dm_run_id',
        'einundzwanzig_pleb_id',
        'status',
        'plaintext',
        'rumor_id',
        'rumor_created_at',
        'wrap',
        'attempts',
        'claimed_at',
        'sent_at',
        'seen_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DmDeliveryStatus::class,
            'rumor_created_at' => 'integer',
            'wrap' => 'array',
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DmRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DmRun::class, 'dm_run_id');
    }

    /**
     * @return BelongsTo<EinundzwanzigPleb, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'einundzwanzig_pleb_id');
    }

    /**
     * @return HasMany<DmDeliveryRelay, $this>
     */
    public function relays(): HasMany
    {
        return $this->hasMany(DmDeliveryRelay::class);
    }
}
