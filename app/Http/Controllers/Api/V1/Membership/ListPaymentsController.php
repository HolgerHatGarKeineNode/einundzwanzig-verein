<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Resources\Api\V1\PaymentEventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * GET /api/v1/membership/payments — the caller's own fee history.
 *
 * A list, but never a list ABOUT members: the query is bound to the one record
 * the signature names, so the collection can only ever hold the annual fees of
 * the person who signed the request. No endpoint on this surface returns a
 * second member's data (plan step 30).
 *
 * An unknown pubkey gets an empty collection rather than a refusal — the same
 * answer a member without any recorded fee gets, and the same answer the caller
 * could derive from `GET /membership/me` anyway. Distinguishing the two would
 * add nothing the caller does not already know about themselves.
 */
class ListPaymentsController extends ApiV1Controller
{
    /**
     * List the annual fees of the signing end user.
     *
     * Newest fee year first. The list is bound to the record the signature
     * names, so it can never hold a second person's fees — no endpoint on this
     * API returns data about another member.
     *
     * A pubkey with no record, and a member who has never been billed, both
     * get an empty list rather than a refusal.
     *
     * `receipt_url` is present only once a fee is settled; an unsettled
     * invoice has no receipt. To pay an open year, call
     * `POST /api/v1/membership/payments/{year}/invoice`.
     */
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $pleb = $this->subject($request);

        $payments = $pleb
            ? $pleb->paymentEvents()->orderByDesc('year')->get()
            : new Collection;

        return PaymentEventResource::collection($payments);
    }
}
