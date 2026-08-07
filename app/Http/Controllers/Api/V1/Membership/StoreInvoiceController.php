<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Resources\Api\V1\InvoiceResource;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * POST /api/v1/membership/payments/{year}/invoice — start (or hand back) the
 * BTCPay checkout for one annual fee.
 *
 * NO GATE ON `statutes_accepted_at`, and that is a decision, not an omission
 * (plan step 25). The statutes demand no explicit consent — Art. 4 asks that
 * members support the association's purpose, and the membership begins with
 * the payment. The checkbox is a product decision. A gate here would have
 * locked out precisely those long-standing members whose membership the
 * statutes already recognise, and who have nothing to consent to retroactively.
 *
 * WHAT IS NOT TAKEN FROM THE REQUEST: the amount, the currency and the fee
 * year. The first two come from the configuration through `MembershipService`,
 * and the year is the current one. A body naming any of them is ignored — not
 * rejected, because a client sending `{"amount": 1}` is not to be helped with
 * an error message but to be charged the correct fee.
 *
 * Which is also why `{year}` may only ever be the current fee year. The
 * general assembly fixes the fee per year (Art. 4), so an invoice for a past
 * year would book today's amount against a year that owed a different one, and
 * a future year's fee has not been decided yet. Worse, a settled fee for any
 * year at all constitutes a membership — leaving the year open would let
 * somebody join by paying a fee for 1970. A different year is refused through
 * the shared `notFound()`: that invoice does not exist and cannot be made to.
 *
 * A pubkey with no member record is refused the same way. The path into the
 * association is `POST /applications`, and creating a record here would spend
 * the association's BTCPay key on a stranger who has agreed to nothing.
 */
class StoreInvoiceController extends ApiV1Controller
{
    public function __invoke(Request $request, string $year): InvoiceResource
    {
        $pleb = $this->subject($request);
        $currentYear = $this->membership->currentYear();

        if (! $pleb || (int) $year !== $currentYear) {
            $this->notFound();
        }

        try {
            $result = $this->membership->createInvoice($pleb, $currentYear);
        } catch (HttpClientException) {
            /*
             * Same answer and same reasoning as the refresh endpoint: BTCPay
             * is unreachable or answered something unusable, so no invoice
             * exists to hand out. That includes a 200 carrying no invoice id —
             * the service refuses it rather than storing a phantom reference,
             * and a client must not be told "created" about a checkout that
             * does not exist.
             */
            throw new ServiceUnavailableHttpException(message: 'Service Unavailable.');
        }

        return new InvoiceResource([
            'payment_event' => $result['payment_event'],
            'checkout_url' => $result['checkout_url'],
            'created' => $result['created'],
        ]);
    }
}
