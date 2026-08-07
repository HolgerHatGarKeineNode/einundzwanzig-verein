<?php

use App\Enums\AssociationStatus;
use App\Http\Controllers\Api\V1\ApiV1Controller;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

/*
 * ONE WORDING FOR EVERY REFUSAL.
 *
 * "This pubkey has no record", "the record is not a member" and "the member has
 * not paid" must be indistinguishable in the response. A caller who can tell
 * them apart holds a lookup oracle for the association's membership roll.
 *
 * SCOPE, STATED PLAINLY. The three read endpoints of this run do NOT 404 in
 * these cases, and deliberately so: `GET /membership/me` has to be able to
 * answer "you are not a member yet" — that is what `membership_status: none`
 * is for, and refusing it would make the endpoint useless to the very person
 * it exists for. There is no oracle in that answer either, because the subject
 * comes solely from the NIP-98 signature: a caller can ask about themselves and
 * about nobody else. (See the report accompanying this run: plan step 29 and
 * plan step 31 pull in opposite directions here, and step 31 is the one the
 * endpoint's purpose depends on.)
 *
 * What is pinned here is therefore the shared building block, `notFound()` on
 * ApiV1Controller — the one run 2's endpoints will call, where a genuinely
 * absent resource does arise (an invoice for a year that has none, a deletion
 * of a record that does not exist).
 */

const NF_CLIENT_KEY = 'nf111111111111111111111111111111111111111111111111111111111nf11';

/**
 * Three different reasons to refuse, all funnelled through the same helper.
 */
class NotFoundProbeController extends ApiV1Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $pleb = $this->subject($request);

        if (! $pleb) {
            $this->notFound();
        }

        if ($pleb->association_status === AssociationStatus::DEFAULT) {
            $this->notFound();
        }

        if (! $pleb->hasPaidMembership()) {
            $this->notFound();
        }

        return response()->json(['data' => ['confirmed' => true]]);
    }
}

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => NF_CLIENT_KEY],
        /*
         * Asserted with debug off, and that is not a convenience: with
         * APP_DEBUG=true Laravel appends the throwing file and LINE NUMBER to
         * the body, so the three refusals — three different lines in the probe
         * above — would be distinguishable byte for byte. That is exactly the
         * oracle this test denies, and exactly why the audit demands
         * APP_DEBUG=false in production (plan, P4 input item 9).
         */
        'app.debug' => false,
    ]);

    Route::prefix('api')->middleware(['api', 'api.v1'])
        ->get('/v1/_not-found-probe', NotFoundProbeController::class)
        ->name('api.v1.test.not-found-probe');
});

/**
 * @param  array<string, mixed>|null  $attributes  null = the pubkey has no record at all
 */
function nfSignedCall(?array $attributes = null, bool $paid = false): TestResponse
{
    $privkey = (new Key)->generatePrivateKey();
    $pubkey = (new Key)->getPublicKey($privkey);

    if ($attributes !== null) {
        $pleb = EinundzwanzigPleb::factory()->create($attributes + [
            'pubkey' => $pubkey,
            'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
        ]);

        if ($paid) {
            PaymentEvent::factory()->paid()->create([
                'einundzwanzig_pleb_id' => $pleb->id,
                'year' => (int) now()->year,
            ]);
        }
    }

    return apiV1SignedGet('/api/v1/_not-found-probe', NF_CLIENT_KEY, $privkey)['response'];
}

it('answers three different refusals with byte-identical responses', function () {
    $doesNotExist = nfSignedCall();

    $notAMember = nfSignedCall([
        'association_status' => AssociationStatus::DEFAULT,
        'email' => 'not-a-member@example.test',
    ]);

    $memberNotPaid = nfSignedCall([
        'association_status' => AssociationStatus::ACTIVE,
        'email' => 'unpaid@example.test',
    ]);

    foreach ([$doesNotExist, $notAMember, $memberNotPaid] as $response) {
        $response->assertNotFound();
    }

    // Byte for byte, not "the same shape".
    expect($notAMember->getContent())->toBe($doesNotExist->getContent())
        ->and($memberNotPaid->getContent())->toBe($doesNotExist->getContent())
        // And the one wording carries nothing beyond the wording. (Compared on
        // the decoded value, because Laravel pretty-prints JSON error bodies
        // outside production — whitespace differs per environment, the payload
        // does not, and it is the payload that must not vary per cause.)
        ->and($doesNotExist->json())->toBe(['message' => ApiV1Controller::NOT_FOUND_MESSAGE]);

    // And the status line carries no hint either.
    expect($notAMember->status())->toBe($doesNotExist->status())
        ->and($memberNotPaid->status())->toBe($doesNotExist->status());

    expect($doesNotExist->getContent())
        ->not->toContain('not-a-member@example.test')
        ->not->toContain('unpaid@example.test');
});

it('confirms only the case that actually holds', function () {
    /*
     * The discriminating half. Without it, a helper that answered 404 to
     * everything — including a paid member — would satisfy the test above.
     */
    $confirmed = nfSignedCall(['association_status' => AssociationStatus::ACTIVE], paid: true);

    $confirmed->assertOk()->assertJsonPath('data.confirmed', true);
});
