<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Http\Resources\Api\V1\MembershipExportResource;
use App\Models\Profile;
use Illuminate\Http\Request;

/**
 * GET /api/v1/membership/export — the data-subject access request.
 *
 * The subject comes out of the NIP-98 signature like everywhere else, which is
 * what makes an endpoint of this reach defensible at all: it hands out the
 * e-mail address and the free-form application text, and it can only ever hand
 * them to the person who holds the private key they belong to.
 *
 * A pubkey with nothing on file gets 200 and an empty record rather than 404.
 * "Nothing is stored about you" is a complete and truthful answer to an access
 * request, and refusing it would make a data subject unable to tell an empty
 * file apart from a broken endpoint.
 *
 * Read-only by construction — an access request must not change the record it
 * reports on, least of all bring one into existence.
 */
class ExportDataController extends ApiV1Controller
{
    public function __invoke(Request $request): MembershipExportResource
    {
        $pubkey = $this->subjectPubkey($request);
        $pleb = $this->subject($request);

        $pleb?->load([
            'paymentEvents' => fn ($query) => $query->orderByDesc('year'),
            'membershipGrants' => fn ($query) => $query->orderByDesc('year'),
        ]);

        return new MembershipExportResource([
            'pubkey' => $pubkey,
            'pleb' => $pleb,
            'membership_status' => $this->membership->membershipStatus($pleb),
            /*
             * Queried by pubkey, not through the relation: the cached kind-0
             * profile is keyed by pubkey and exists independently of whether a
             * member record was ever created.
             */
            'profile' => Profile::query()->where('pubkey', $pubkey)->first(),
        ]);
    }
}
