<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Resources\Api\V1\MembershipConfigResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/membership/config — what joining costs and what an application
 * must carry. SERVES BOTH SURFACES: the app branch routes
 * `GET /api/v1/app/membership/config` to this same controller, because the
 * answer is association-wide and there is nothing about it a branch could
 * change.
 *
 * THE ONLY ENDPOINT OF THE MAIN SURFACE WITHOUT NIP-98, and the condition for
 * that is visible in the code: it never touches `subject()`, never reads
 * `ApiIdentity::pubkey()` and never queries a member record. A client has to be
 * able to show the fee before anyone has signed anything — requiring a
 * signature to learn the price would mean a prospective member must
 * authenticate to be told what they would be authenticating for.
 *
 * The client key still applies, so the call is attributable and counted. What
 * falls away is only the end-user identity, and with it the only thing that
 * could make this response differ between two callers.
 *
 * If a subject-dependent field is ever added here, this exception has to be
 * withdrawn in the same commit. `ShowConfigTest` asserts the response against a
 * closed key list precisely so that cannot happen quietly.
 */
class ShowConfigController extends ApiV1Controller
{
    /**
     * Get the annual fee and what an application must carry.
     *
     * Send the client key alone — this endpoint needs no NIP-98 signature on
     * either surface. A client has to be able to show the fee before anybody
     * has signed anything, and the response is association-wide: it is
     * identical for every caller and on both branches.
     *
     * `year` is the fee year the association is currently collecting, and it
     * is the only year the invoice endpoint of the same branch accepts.
     * `application.required_fields` and `application.optional_fields` name the
     * body fields of the application endpoint of the same branch.
     */
    public function __invoke(Request $request): MembershipConfigResource
    {
        return new MembershipConfigResource($this->membership);
    }
}
