<?php

namespace App\Http\Controllers\Api\V1\Membership;

use App\Http\Controllers\Api\V1\ApiV1Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DELETE /api/v1/membership/me — erase the person, keep the books.
 *
 * NOT a row deletion, and the reason is in the schema: `payment_events` and
 * `membership_grants` cascade off the member record, so dropping it would take
 * the association's proof of which annual fees it received with it. A club has
 * to be able to account for its membership fees, and the statutes rule out any
 * claim to a refund (Art. 4.2) — the booking is not the member's to withdraw.
 * Erasure under the revised Swiss DSG, and under Art. 17(3) GDPR for members
 * in the EU, is the removal of the personal reference, not of the entry.
 *
 * `MembershipService::erasePersonalData()` does the work and documents every
 * single field decision — what is cleared, what is replaced by a random
 * tombstone, and what is kept and why. It lives there rather than here because
 * it is a domain rule about what a membership record consists of, not a
 * property of this transport.
 *
 * IDEMPOTENT BY OUTCOME AND BY STATUS. A second call answers 200 again, not
 * 404. What the caller asked for is a postcondition — "no personal data of
 * this pubkey is stored here" — and after the first call that is true and
 * stays true. Answering 404 would turn a fulfilled erasure request into an
 * error, and a client retrying after a timeout could not tell "already done"
 * from "failed". Nothing is disclosed by it either: the caller is being told
 * about themselves, and they cannot address any other record.
 *
 * The one thing a repeat call cannot repeat is the COUNT of retained
 * bookkeeping entries — see the field's own comment below. It reports null
 * rather than a number it is no longer able to establish, and reporting zero
 * there was a defect: it told a retrying client that nothing had survived
 * their erasure when in fact the annual fees had.
 *
 * A LOGIN AFTER THE ERASURE IS NOT BLOCKED, and that too is a decision. The
 * next signature from that pubkey meets `NostrAuth::ensurePleb()` and gets a
 * fresh, empty record — DEFAULT(1), no application, no fee history. Erasure is
 * not a ban and the association has no lawful basis for keeping a "do not
 * serve" list of pubkeys; a returning person starts as a stranger, which is
 * exactly what erasure means. The anonymised booking stays behind,
 * unreachable, and is never re-attached — the tombstone is random, so nothing
 * can ever link it back.
 */
class DeleteMembershipController extends ApiV1Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $pleb = $this->subject($request);

        return response()->json([
            'data' => [
                /*
                 * A statement about the state after this call, not about how
                 * much work it took: no personal data of this pubkey is
                 * stored. True on the first call and on every one after it.
                 */
                'erased' => true,
                /*
                 * How many annual fees remain as anonymised bookkeeping
                 * entries — a data subject is entitled to know what survives
                 * their request.
                 *
                 * NULL, NOT ZERO, ONCE THERE IS NOTHING LEFT TO ERASE. Zero
                 * was a false statement of exactly the kind this field exists
                 * to prevent: the entries do survive, and a client repeating
                 * the call after a timeout was told they did not.
                 *
                 * The honest answer on a repeat is "not determinable any
                 * more", and it cannot be anything else BY CONSTRUCTION: the
                 * erased row is reachable only through the pubkey, and the
                 * pubkey has been replaced by a random tombstone precisely so
                 * that no one — including this endpoint — can find their way
                 * back to it. Counting over that row a second time would
                 * require the recomputable link the erasure exists to destroy.
                 * The missing number is not a gap in the answer; it is the
                 * evidence that the erasure worked.
                 */
                'retained_payments' => $pleb
                    ? $this->membership->erasePersonalData($pleb)['retained_payments']
                    : null,
            ],
        ]);
    }
}
