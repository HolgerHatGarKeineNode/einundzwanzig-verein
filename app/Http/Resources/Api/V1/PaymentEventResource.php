<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentEvent;
use App\Services\BtcPayClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One annual fee of the calling end user.
 *
 * The internal row id stays inside: it is a sequential counter over every
 * payment the association ever recorded, so handing it out tells a caller
 * roughly how many members paid before them. `year` is the identifier a client
 * needs, and it is the one the endpoints of run 2 address a payment by
 * (`/payments/{year}/invoice`, `/payments/{year}/refresh`).
 *
 * `btc_pay_invoice` is likewise not exposed as such. The receipt link is what
 * a member can actually use, and only once the invoice is settled — an
 * unsettled invoice id would be a checkout link dressed up as a receipt.
 *
 * @property-read PaymentEvent $resource
 */
class PaymentEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoiceId = $this->resource->btc_pay_invoice;
        $paid = (bool) $this->resource->paid;

        return [
            'year' => (int) $this->resource->year,
            'amount' => (int) $this->resource->amount,
            'currency' => (string) config('einundzwanzig.config.currency'),
            'paid' => $paid,
            'receipt_url' => $paid && is_string($invoiceId) && $invoiceId !== ''
                ? app(BtcPayClient::class)->receiptUrl($invoiceId)
                : null,
        ];
    }
}
