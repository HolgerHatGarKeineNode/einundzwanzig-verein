<?php

namespace App\Models;

use App\Enums\PaymentReviewReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment that could not be booked and needs a human to look at it.
 *
 * Written only by MembershipService — see the migration for why this is a
 * table and not a log line.
 */
class PaymentReview extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payment_event_id',
        'einundzwanzig_pleb_id',
        'reason',
        'source',
        'btc_pay_invoice',
        'delivery_id',
        'expected_amount',
        'expected_currency',
        'observed_amount',
        'observed_currency',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_amount' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * The reason as the enum rather than the raw column.
     *
     * `reason` stays a plain string in the database so that an unknown value
     * written by an older or newer version of the code cannot make a row
     * unreadable — but everything that DECIDES on it (above all: does this
     * refusal warrant a retry?) has to go through the enum.
     */
    public function reasonCase(): ?PaymentReviewReason
    {
        return PaymentReviewReason::tryFrom((string) $this->reason);
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
