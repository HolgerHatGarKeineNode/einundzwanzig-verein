<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * One row per BTCPay webhook delivery that carries a side effect.
 *
 * Only `InvoiceSettled` and `InvoiceInvalid` are recorded. The chatty types
 * (`InvoiceReceivedPayment`, `InvoiceProcessing`, …) change nothing here, so
 * booking them would grow the table without ever being read.
 *
 * Which field is the key, and the source evidence for it: see the migration.
 */
class BtcPayWebhookDelivery extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'delivery_key',
        'delivery_id',
        'type',
        'invoice_id',
        'is_redelivery',
        'manually_marked',
        'over_paid',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_redelivery' => 'boolean',
            'manually_marked' => 'boolean',
            'over_paid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Take ownership of a delivery, or report that it is already done.
     *
     * Returns null when this event has already been processed to completion —
     * the caller then answers 204 and does nothing, which is what makes a
     * redelivery harmless.
     *
     * The insert is allowed to lose: two deliveries of the same event arriving
     * at once both try to create the row, the unique index picks a winner, and
     * the loser reads the winner's row instead of surfacing a 500. Whether it
     * then re-runs the work is decided by `processed_at` alone — an unfinished
     * claim must be picked up again, or a delivery that died halfway would be
     * marked "seen" forever without its effects ever having happened.
     */
    /**
     * @param  array<string, mixed>  $attributes  Event flags worth keeping (manually_marked, over_paid)
     */
    public static function claim(string $key, string $type, ?string $deliveryId, ?string $invoiceId, bool $isRedelivery, array $attributes = []): ?self
    {
        try {
            $delivery = static::query()->create($attributes + [
                'delivery_key' => $key,
                'delivery_id' => $deliveryId,
                'type' => $type,
                'invoice_id' => $invoiceId,
                'is_redelivery' => $isRedelivery,
            ]);
        } catch (UniqueConstraintViolationException) {
            $delivery = static::query()->where('delivery_key', $key)->first();
        }

        if (! $delivery || $delivery->processed_at !== null) {
            return null;
        }

        return $delivery;
    }

    public function markProcessed(): void
    {
        $this->forceFill(['processed_at' => now()])->save();
    }
}
