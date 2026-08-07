<?php

namespace App\Http\Controllers;

use App\Models\BtcPayWebhookDelivery;
use App\Models\PaymentEvent;
use App\Services\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * BTCPay tells us a fee has been paid — and since P2 that is what makes
 * somebody a member. This is therefore no longer a bookkeeping endpoint but
 * the joining path, and it is the only part of that path that arrives from
 * outside the building.
 *
 * The three checks at the top are unchanged from before this phase and stay
 * exactly as they were: the shared secret must exist, the HMAC must verify
 * timing-safely, and the store id must be ours. Everything below them is new.
 *
 * WHAT THIS CONTROLLER DELIBERATELY DOES NOT DO: decide who becomes a member,
 * or on what terms. It resolves the delivery to a payment event and hands that
 * to `MembershipService::markPaid()` / `reversePayment()`. The amount check,
 * the currency check, the year check, the transaction and the audit trail all
 * live there, so `POST /payments/{year}/refresh` — and whatever the next
 * caller turns out to be — is bound by the identical rules. The measured
 * defect this replaces was precisely the opposite arrangement: a bare
 * `PaymentEvent::where(...)->update(['paid' => true])` here, with the
 * promotion logic sitting in a service nobody on this path called.
 *
 * STATUS CODES ARE PART OF THE PROTOCOL, because BTCPay reads them and retries
 * on 5xx, 429, 408 and connection errors (WebhookSender.cs:194-206, master,
 * fetched 2026-08-07). So: a failure we might recover from must be a 5xx and
 * must leave the delivery unfinished, and a permanent refusal must not be —
 * otherwise a single unmatchable invoice buys itself eight redeliveries over
 * an hour, every time.
 */
class BtcPayWebhookController extends Controller
{
    /**
     * Deliveries that change something here. Everything else is acknowledged
     * and dropped, exactly as before — `InvoiceProcessing`,
     * `InvoiceReceivedPayment` and friends carry no decision for us.
     *
     * @var list<string>
     */
    private const HANDLED_TYPES = ['InvoiceSettled', 'InvoiceInvalid'];

    public function __construct(private readonly MembershipService $membership) {}

    public function __invoke(Request $request): Response
    {
        $secret = (string) config('services.btc_pay.webhook_secret');

        if ($secret === '') {
            abort(Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $request->header('BTCPay-Sig'))) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if ($request->input('storeId') !== config('services.btc_pay.store_id')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $type = (string) $request->input('type');
        $invoiceId = (string) $request->input('invoiceId');

        if (! in_array($type, self::HANDLED_TYPES, true) || $invoiceId === '') {
            return response()->noContent();
        }

        $settlement = [
            /*
             * `manuallyMarked` is the one flag here that changes what a
             * membership MEANS: it says a person clicked "mark settled" in the
             * BTCPay backend and no payment was ever observed. The amount
             * check offers nothing against it — a manual settle reports the
             * invoiced amount by construction, so the comparison succeeds
             * every time. Written down now or unknowable later.
             */
            'manually_marked' => $request->has('manuallyMarked') ? $request->boolean('manuallyMarked') : null,
            'over_paid' => $request->has('overPaid') ? $request->boolean('overPaid') : null,
        ];

        $delivery = BtcPayWebhookDelivery::claim(
            $this->deliveryKey($request, $type, $invoiceId),
            $type,
            $this->stringInput($request, 'deliveryId'),
            $invoiceId,
            (bool) $request->boolean('isRedelivery'),
            $settlement,
        );

        /*
         * Already handled to completion. This is the whole point of the
         * ledger: the old idempotency was an accident of
         * `where('paid', false)`, which stopped protecting anything the moment
         * a delivery also raised a category and wrote a grant.
         */
        if (! $delivery) {
            return response()->noContent();
        }

        $paymentEvent = PaymentEvent::query()
            ->where('btc_pay_invoice', $invoiceId)
            ->orderByDesc('id')
            ->first();

        if (! $paymentEvent) {
            /*
             * Settled money against a claim we cannot find. Recorded rather
             * than ignored — this is the orphan the reconciliation command
             * chases — and answered 404 rather than 204, so the failure is
             * visible in BTCPay's own delivery list. A 4xx is not retried, and
             * that is correct: no number of redeliveries will make the row
             * appear.
             */
            $this->membership->flagUnknownInvoice($invoiceId, 'webhook', $delivery->delivery_key);
            $delivery->markProcessed();

            abort(Response::HTTP_NOT_FOUND);
        }

        /*
         * Anything thrown from here on (BTCPay unreachable while verifying the
         * amount, a database failure mid-transaction) propagates into a 5xx
         * and leaves `processed_at` unset — so BTCPay's next attempt does the
         * work rather than finding a "done" marker over a job that never ran.
         */
        if ($type === 'InvoiceSettled') {
            $result = $this->membership->markPaid(
                $paymentEvent,
                source: 'webhook',
                deliveryId: $delivery->delivery_key,
                settlement: $settlement,
            );

            /*
             * A REFUSAL IS NOT ALWAYS A VERDICT, and the difference decides
             * whether this delivery is recoverable.
             *
             * BTCPay retries on 5xx and stops for good on a 2xx. Acknowledging
             * a TRANSIENT refusal — the store answered with an unreadable body,
             * or has not caught up with its own settled event yet — marked the
             * delivery done and left the redelivery that would have carried the
             * right answer with nothing to do. A momentary upstream fault
             * became a permanently unbooked payment.
             *
             * A wrong amount or a wrong currency is the opposite: an answer.
             * Retrying it eight times over an hour changes nothing, so it is
             * acknowledged and the review row is the follow-up.
             */
            if ($result['review']?->reasonCase()?->isTransient()) {
                abort(Response::HTTP_SERVICE_UNAVAILABLE);
            }
        } else {
            $this->membership->reversePayment($paymentEvent, 'InvoiceInvalid', 'webhook', $delivery->delivery_key);
        }

        $delivery->markProcessed();

        return response()->noContent();
    }

    /**
     * The value that identifies this EVENT rather than this attempt.
     *
     * `originalDeliveryId` and not `deliveryId`: BTCPay mints a fresh
     * `deliveryId` for every retry while carrying the original one along
     * unchanged, and it is populated from the very first delivery
     * (WebhookProviderHostedService.cs:48-52 sets both to the same value,
     * WebhookSender.cs:96-99 replaces only `DeliveryId`). Keying on the wrong
     * one would have let every automatic retry through while looking correct.
     * The full quotations are in the migration.
     *
     * The fallback is for a sender that supplies neither — not BTCPay as it
     * stands, but the ledger must not silently degrade into "no key, no
     * de-duplication". `timestamp` is set once when the delivery is first
     * created and is NOT rewritten on redelivery (only DeliveryId, WebhookId,
     * OriginalDeliveryId and IsRedelivery are), so type + invoice + timestamp
     * still identifies the event across attempts.
     */
    private function deliveryKey(Request $request, string $type, string $invoiceId): string
    {
        $key = $this->stringInput($request, 'originalDeliveryId')
            ?? $this->stringInput($request, 'deliveryId');

        if ($key !== null) {
            return $key;
        }

        return 'derived:'.hash('sha256', implode('|', [
            $type,
            $invoiceId,
            (string) $request->input('timestamp'),
        ]));
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
