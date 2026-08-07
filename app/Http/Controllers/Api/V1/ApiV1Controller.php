<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EinundzwanzigPleb;
use App\Services\MembershipService;
use App\Support\ApiIdentity;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Everything every /api/v1 endpoint has to get right, in one place.
 *
 * Three rules live here rather than in each controller, because a rule that is
 * re-implemented per endpoint is a rule that will be forgotten at the fourth
 * one:
 *
 *  1. the subject comes from the signature and from nowhere else,
 *  2. the pubkey is validated canonically before it ever reaches the database,
 *  3. "no" is said in exactly one wording, so the answer carries no
 *     information about which precondition failed.
 */
abstract class ApiV1Controller extends Controller
{
    /**
     * The single wording for every refusal to confirm a subject.
     *
     * One constant, because the point is byte-identity: "this pubkey has no
     * record", "the record is not a member" and "the member has not paid" must
     * be indistinguishable in the response. A caller who can tell them apart
     * has a lookup oracle for the association's membership roll.
     */
    public const NOT_FOUND_MESSAGE = 'Not Found.';

    public function __construct(protected MembershipService $membership) {}

    /**
     * The verified pubkey of the end user, re-validated against the canonical
     * NIP-01 spelling.
     *
     * `Nip98::decode()` already enforces this and a failure here would mean the
     * auth layer was bypassed or rewired — which is exactly why the check is
     * repeated instead of trusted. An unvalidated pubkey reaching a query is
     * how `firstOrCreate(['pubkey' => …])` ends up holding several rows for one
     * person, each with its own membership and its own rate-limit bucket.
     */
    protected function subjectPubkey(Request $request): string
    {
        $pubkey = ApiIdentity::pubkey($request);

        if (preg_match('/^[0-9a-f]{64}$/', $pubkey) !== 1) {
            throw new HttpException(401, 'Unauthorized.');
        }

        return $pubkey;
    }

    /**
     * The member record of the signed-in end user, or null if there is none.
     *
     * Deliberately a read: a GET must not bring a record into existence. That
     * was the defect behind P1 step 6, where an unauthenticated GET created a
     * member row for every pubkey it was handed.
     */
    protected function subject(Request $request): ?EinundzwanzigPleb
    {
        return EinundzwanzigPleb::query()
            ->where('pubkey', $this->subjectPubkey($request))
            ->first();
    }

    /**
     * Refuse without saying why.
     *
     * @throws NotFoundHttpException
     */
    protected function notFound(): never
    {
        throw new NotFoundHttpException(self::NOT_FOUND_MESSAGE);
    }
}
