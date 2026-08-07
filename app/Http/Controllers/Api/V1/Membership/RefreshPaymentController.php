<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Services\BtcPayClient;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * POST /api/v1/membership/payments/{year}/refresh — ask BTCPay what became of
 * an invoice and book the answer.
 *
 * The webhook is the joining path and it arrives from outside. If a delivery
 * is lost, somebody has paid and is not a member, and nobody finds out. This
 * endpoint is the client-driven repair for that: it runs through
 * `MembershipService::refreshPaymentStatus()` and therefore through the same
 * `markPaid()` and `grantMembershipOnPayment()` the webhook uses. No promotion
 * rule is restated here — a second implementation of "what makes someone a
 * member" is exactly the second truth this API exists to avoid.
 *
 * Unlike invoice creation this accepts any year: an unsettled invoice from a
 * previous year is still worth resolving, and a refresh creates nothing. What
 * it cannot do is invent a subject — no record, no fee for that year, or a fee
 * that never had an invoice all end at the shared `notFound()`, because there
 * is genuinely nothing upstream to ask about.
 *
 * WHEN BTCPAY IS DOWN the answer is 503 and `paid` is untouched. Fail closed:
 * an unreachable payment provider means the status is UNKNOWN, and the only
 * safe reading of an unknown payment is the one already on record. The catch
 * is narrowed to the HTTP client's own exception type on purpose — a bug in
 * the service must not be able to disguise itself as a provider outage.
 */
class RefreshPaymentController extends ApiV1Controller
{
    public function __invoke(Request $request, string $year): InvoiceResource
    {
        $pleb = $this->subject($request);

        $paymentEvent = $pleb?->paymentEvents()
            ->where('year', (int) $year)
            ->orderByDesc('id')
            ->first();

        if (! $paymentEvent || ! $paymentEvent->btc_pay_invoice) {
            $this->notFound();
        }

        try {
            $result = $this->membership->refreshPaymentStatus($paymentEvent);
        } catch (HttpClientException) {
            throw new ServiceUnavailableHttpException(message: 'Service Unavailable.');
        }

        $refreshed = $result['payment_event'];
        $invoiceId = (string) $refreshed->btc_pay_invoice;

        return new InvoiceResource([
            'payment_event' => $refreshed,
            /*
             * Null after a released invoice: BTCPay reported the checkout as
             * expired or invalid, the fee year was freed up and there is no
             * page left to send a payer to. The client's next step is
             * `POST /payments/{year}/invoice`, which will start a fresh one.
             */
            'checkout_url' => $invoiceId === ''
                ? null
                : app(BtcPayClient::class)->checkoutUrl($invoiceId),
            /*
             * Always false. `created` reports whether THIS call created a
             * BTCPay invoice, and a refresh never does — it only reads.
             */
            'created' => false,
        ]);
    }
}
