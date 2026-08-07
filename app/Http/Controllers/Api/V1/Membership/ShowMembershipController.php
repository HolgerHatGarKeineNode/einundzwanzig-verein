<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Resources\Api\V1\MembershipResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/membership/me — the caller's own membership.
 *
 * "Own" is not a filter applied to a requested subject; it is the only subject
 * that can be named. The pubkey comes out of the NIP-98 signature, so a caller
 * can ask about themselves and about nobody else — the private key stays with
 * the end user's signer, which even a compromised client operator does not
 * hold. A pubkey passed in the query or the body is caught by
 * `ApiIdentity::guardClaimedSubject()` in the middleware and never reaches the
 * subject resolution here.
 *
 * The response is 200 in all four membership states, including for a pubkey
 * with no record at all. That is the point of the endpoint: a prospective
 * member has to be able to learn "you are not a member yet" in order to become
 * one. There is no oracle in that — the caller proved possession of the private
 * key and is being told about themselves.
 */
class ShowMembershipController extends ApiV1Controller
{
    public function __invoke(Request $request): MembershipResource
    {
        $pleb = $this->subject($request);

        return new MembershipResource([
            'pubkey' => $this->subjectPubkey($request),
            'status' => $this->membership->status($pleb),
        ]);
    }
}
