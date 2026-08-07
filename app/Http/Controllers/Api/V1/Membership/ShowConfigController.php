<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Resources\Api\V1\MembershipConfigResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/membership/config — what joining costs and what an application
 * must carry.
 *
 * THE ONLY /api/v1 ENDPOINT WITHOUT NIP-98, and the condition for that is
 * visible in the code: it never touches `subject()`, never reads
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
    public function __invoke(Request $request): MembershipConfigResource
    {
        return new MembershipConfigResource($this->membership);
    }
}
