<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Exceptions\MembershipUnavailableException;
use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Requests\Api\V1\StoreInvoiceRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use Illuminate\Http\Client\HttpClientException;
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
 * THE ONE FIELD THAT IS TAKEN is `return_url`, and it is taken because it is
 * the only value a client can send that leaves this application: it becomes
 * `checkout.redirectURL` at BTCPay, whose page then sends the payer's browser
 * there. It must appear on the server-side allowlist
 * (`einundzwanzig.config.invoice_return_urls`) or the call is refused with a
 * 422 — see `StoreInvoiceRequest` for why refusing beats correcting. Omitted,
 * it changes nothing: the payer returns to the association's profile page, as
 * before this field existed.
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
    /**
     * Start, or hand back, the BTCPay checkout for one annual fee.
     *
     * Send the payer to `checkout_url`. `created` is false when an invoice for
     * that year already existed and the caller is being handed that one rather
     * than a second one — that is the normal idempotent answer, not an error.
     *
     * `bolt11` is the Lightning payment request of the same invoice, for a
     * client that can pay it in-app instead of opening the checkout page. It
     * is null when the invoice carries no Lightning payment method, and null
     * again when BTCPay could not be asked in time — the checkout URL next to
     * it works either way, so this field never turns an outage into a refusal.
     * NULL DOES NOT MEAN EXPIRED: BTCPay hands out the payment request of a
     * long-dead invoice unchanged. Read the deadline from the invoice, not
     * from this field; it is the same 1440 minutes the checkout has.
     *
     * AMOUNT, CURRENCY AND FEE YEAR ARE NOT REQUEST VALUES. They come from the
     * association's configuration and from `{year}`; a body naming any of them
     * changes nothing.
     *
     * `return_url` is the single field of the body that is read. It decides
     * where the payer lands after the checkout, must be one of the addresses
     * configured on the server, and answers 422 when it is not. Send none and
     * the payer returns to the association's profile page. It only takes
     * effect on the call that actually creates the invoice — on an idempotent
     * repeat the redirect belongs to the invoice that already exists.
     *
     * `{year}` MUST BE THE CURRENT FEE YEAR, the one
     * `GET /api/v1/membership/config` reports. The general assembly fixes the
     * fee per year, so an invoice for a past year would book today's amount
     * against a year that owed a different one — and since a settled fee for
     * any year constitutes a membership, an open year would let somebody join
     * by paying a fee for 1970. Any other year answers 404.
     *
     * A pubkey with no membership record answers 404 as well. The way in is
     * `POST /api/v1/membership/applications`.
     *
     * 503 means BTCPay is unreachable or the fee is unusable on the current
     * configuration. Nothing was charged and nothing was recorded; retry
     * later. This endpoint carries its own, much tighter quota than the rest
     * of the API — every call spends a request against the association's own
     * BTCPay key.
     */
    public function __invoke(StoreInvoiceRequest $request, string $year): InvoiceResource
    {
        $pleb = $this->subject($request);
        $currentYear = $this->membership->currentYear();

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
            /*
             * Same answer and same reasoning as the refresh endpoint: BTCPay
             * is unreachable or answered something unusable, so no invoice
             * exists to hand out. That includes a 200 carrying no invoice id —
             * the service refuses it rather than storing a phantom reference,
             * and a client must not be told "created" about a checkout that
             * does not exist.
             */
            throw new ServiceUnavailableHttpException(message: 'Service Unavailable.');
        } catch (MembershipUnavailableException) {
            /*
             * The annual fee is not usable — in practice an empty
             * `MEMBERSHIP_FEE`, which casts to 0. Measured before the guard
             * existed: the payload that went out carried `amount: 0`, BTCPay
             * settles such an invoice on sight, and a settled fee is what
             * makes somebody a member. So this is a free membership handed out
             * by a missing environment variable, and the same 503 fits: the
             * endpoint cannot serve on the configuration it has, the client
             * did nothing wrong, and no payload leaves the house. The refusal
             * happens BEFORE the first BTCPay call, not after it.
             */
            throw new ServiceUnavailableHttpException(message: 'Service Unavailable.');
        }

        return new InvoiceResource([
            'payment_event' => $result['payment_event'],
            'checkout_url' => $result['checkout_url'],
            /*
             * BOTH PATHS, and asked for here rather than inside
             * `createInvoice()` because it costs a second BTCPay round trip
             * that the association's own profile page — the other caller of
             * that method — would pay for and never read. `invoice` is the
             * create payload on the first call and null on the idempotent
             * repeat; `lightningInvoiceFor()` handles both, reloading through
             * the stored invoice id when there is no payload, and answers null
             * rather than throwing when BTCPay does not cooperate. Nothing
             * else in this response depends on it.
             */
            'bolt11' => $this->membership->lightningInvoiceFor(
                (string) $result['payment_event']->btc_pay_invoice,
                $result['invoice'],
            ),
            'created' => $result['created'],
        ]);
    }
}
