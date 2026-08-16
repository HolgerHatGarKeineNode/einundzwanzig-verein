<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Exceptions\MembershipUnavailableException;
use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Requests\Api\V1\StoreAppInvoiceRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Models\EinundzwanzigPleb;
use Illuminate\Http\Client\HttpClientException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * POST /api/v1/app/membership/payments/{year}/invoice — the NATIVE APP branch
 * of the invoice endpoint.
 *
 * Behaviour is that of {@see StoreInvoiceController} — same year rule (only
 * the current fee year, `notFound()` for any other), same idempotency via
 * `MembershipService::createInvoice()`, same 503 wall in front of BTCPay
 * outages and an unusable fee configuration. The ONLY difference is the
 * subject: the validated body pubkey instead of the NIP-98 signature (see
 * {@see StoreAppApplicationRequest} for the trust that implies and, equally
 * important, which endpoints deliberately do NOT exist on this surface).
 */
class StoreAppInvoiceController extends ApiV1Controller
{
    public function __invoke(StoreAppInvoiceRequest $request, string $year): InvoiceResource
    {
        $pleb = EinundzwanzigPleb::query()
            ->where('pubkey', $request->subjectPubkey())
            ->first();

        $currentYear = $this->membership->currentYear();

        /*
         * Same wording as the web branch, for the same reason: "no record"
         * and "wrong year" must stay indistinguishable — the record is what
         * an application creates, and its absence is not a membership fact
         * worth disclosing separately.
         */
        if (! $pleb || (int) $year !== $currentYear) {
            $this->notFound();
        }

        try {
            $result = $this->membership->createInvoice(
                $pleb,
                $currentYear,
                returnUrl: $request->returnUrl(),
            );
        } catch (HttpClientException) {
            throw new ServiceUnavailableHttpException(message: 'Service Unavailable.');
        } catch (MembershipUnavailableException) {
            throw new ServiceUnavailableHttpException(message: 'Service Unavailable.');
        }

        return new InvoiceResource([
            'payment_event' => $result['payment_event'],
            'checkout_url' => $result['checkout_url'],
            'bolt11' => $this->membership->lightningInvoiceFor(
                (string) $result['payment_event']->btc_pay_invoice,
                $result['invoice'],
            ),
            'created' => $result['created'],
        ]);
    }
}
