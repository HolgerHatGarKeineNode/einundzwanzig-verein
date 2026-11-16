<?php

namespace App\Models;

use App\Enums\DmDeliveryStatus;
use App\Jobs\Dm\SendDirectMessage;
use Carbon\CarbonInterface;
use Database\Factories\DmDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
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
     * Deliveries a send still has to do something for: `pending`, `failed`,
     * and `claimed` by a worker that died (claim older than
     * SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS). The one definition for
     * the fan-out and for DirectMessagePause::resume().
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithWorkLeft(Builder $query, CarbonInterface $now): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereIn('status', DmDeliveryStatus::claimableValues())
            ->orWhere(fn (Builder $query) => $query
                ->where('status', DmDeliveryStatus::Claimed->value)
                ->where('claimed_at', '<', $now->copy()->subSeconds(SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS))));
    }

    /**
     * Failed in the board's eyes: `failed`, or `claimed` by a worker that
     * died (the same expiry as scopeWithWorkLeft). A re-send picks up
     * exactly these.
     *
     * @param  Builder<self>  $query
     */
    public function scopeFailedOrStuck(Builder $query, CarbonInterface $now): void
    {
        $query->withWorkLeft($now)->where('status', '!=', DmDeliveryStatus::Pending->value);
    }

    /**
     * A claim older than SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS: its
     * worker is gone and nothing is being sent. Reads as failed.
     */
    public function isStuck(CarbonInterface $now): bool
    {
        return $this->status === DmDeliveryStatus::Claimed
            && $this->claimed_at !== null
            && $this->claimed_at->lt($now->copy()->subSeconds(SendDirectMessage::CLAIM_EXPIRES_AFTER_SECONDS));
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
