<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PaymentEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A BTCPay checkout for one annual fee.
 *
 * Built in run 1 although no route reaches it yet: the shape is not a guess.
 * `MembershipService::createInvoice()` already returns exactly
 * `{payment_event, invoice, checkout_url, created}`, and run 2 wires
 * `POST /payments/{year}/invoice` and `POST /payments/{year}/refresh` to it.
 * Fixing the response here keeps the two endpoints from inventing two
 * different answers to the same question.
 *
 * `created` is the honest report of an idempotent call: false means an invoice
 * for that year already existed and the caller is being handed that one rather
 * than a second one. It is not an error, and a client must not treat it as one.
 *
 * The raw BTCPay payload is deliberately dropped. It carries store ids,
 * internal invoice metadata and the `posData` the association wrote for its own
 * bookkeeping — none of it is a third-party client's business, and passing an
 * upstream body through unfiltered is how the next field BTCPay adds becomes
 * part of this API without anyone deciding so.
 *
 * `bolt11` is the Lightning payment request of the same invoice — the one
 * field that lets a client with a wallet pay without opening the checkout page
 * at all. It is nullable and additive: every client that ignores it keeps
 * working exactly as before, and a client that reads it must treat null as
 * "use `checkout_url`". NULL IS NOT AN EXPIRY SIGNAL — BTCPay hands out the
 * payment request of an invoice that died hundreds of hours ago unchanged, so
 * a client asking "can this still be paid" has to read the invoice's own
 * deadline. It is also null whenever the extra BTCPay read behind it failed,
 * which is why nothing else in this response depends on it.
 *
 * @property-read array{payment_event: PaymentEvent, checkout_url: string|null, bolt11: string|null, created: bool} $resource
 */
class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'checkout_url' => $this->resource['checkout_url'],
            /*
             * `?? null` so that a caller which has no Lightning payment
             * request to offer says so by omission rather than by having to
             * spell out a null it never looked up. The alternative — an
             * undefined-key error — would turn "no BOLT11" into a 500 in the
             * one place whose whole design is that a missing BOLT11 costs
             * nothing.
             */
            'bolt11' => $this->resource['bolt11'] ?? null,
            'created' => $this->resource['created'],
            'payment' => (new PaymentEventResource($this->resource['payment_event']))->toArray($request),
        ];
    }
}
