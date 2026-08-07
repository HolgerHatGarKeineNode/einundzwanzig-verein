<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A booked annual fee that was taken back again — the Storno record.
 *
 * Written only by MembershipService::reversePayment(). It never carries a
 * status change: see the migration.
 */
class PaymentReversal extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payment_event_id',
        'einundzwanzig_pleb_id',
        'year',
        'amount',
        'currency',
        'reason',
        'source',
        'delivery_id',
        'reversed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'amount' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    public function paymentEvent(): BelongsTo
    {
        return $this->belongsTo(PaymentEvent::class);
    }

    public function pleb(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class, 'einundzwanzig_pleb_id');
    }
}
