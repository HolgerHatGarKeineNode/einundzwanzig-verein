<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use Illuminate\Support\Carbon;
use swentel\nostr\Key\Key;

const ME_CLIENT_KEY = 'me1111111111111111111111111111111111111111111111111111111111me1';

beforeEach(function () {
    config(['einundzwanzig.config.api_client_keys' => [
        'einundzwanzig-group' => ME_CLIENT_KEY,
    ]]);
});

/**
 * A member record for the pubkey a signed request will carry.
 *
 * @param  array<string, mixed>  $attributes
 */
function meMemberFor(string $privkey, array $attributes = []): EinundzwanzigPleb
{
    $pubkey = (new Key)->getPublicKey($privkey);

    return EinundzwanzigPleb::factory()->create($attributes + [
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);
}

it('requires a NIP-98 signature', function () {
    $this->withHeaders([
        'X-Api-Key' => ME_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->get('/api/v1/membership/me')->assertUnauthorized();
});

it('requires a client key', function () {
    $signed = makeNip98Event(url('/api/v1/membership/me'));

    $this->withHeaders([
        'Authorization' => $signed['header'],
        'Accept' => 'application/json',
    ])->get('/api/v1/membership/me')->assertUnauthorized();
});

/*
 * ---------------------------------------------------------------------------
 * The four membership states, each through the endpoint a client actually
 * calls.
 * ---------------------------------------------------------------------------
 */

it('reports "none" for a pubkey that has no record at all', function () {
    $call = apiV1SignedGet('/api/v1/membership/me', ME_CLIENT_KEY);

    $call['response']->assertOk()
        ->assertJsonPath('data.membership_status', 'none')
        ->assertJsonPath('data.association_status', 'DEFAULT')
        ->assertJsonPath('data.applied_at', null);

    // A GET must not bring a record into existence — that was the defect P1
    // removed from GET /api/nostr/profile/{key}.
    expect(EinundzwanzigPleb::where('pubkey', $call['pubkey'])->exists())->toBeFalse();
});

it('reports "none" for a record that never applied', function () {
    $privkey = (new Key)->generatePrivateKey();
    meMemberFor($privkey, [
        'association_status' => AssociationStatus::DEFAULT,
        'applied_at' => null,
    ]);

    apiV1SignedGet('/api/v1/membership/me', ME_CLIENT_KEY, $privkey)['response']
        ->assertOk()
        ->assertJsonPath('data.membership_status', 'none');
});

it('reports "awaiting_payment" after an application while the year is unpaid', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pleb = meMemberFor($privkey, [
        'association_status' => AssociationStatus::DEFAULT,
        'applied_at' => now(),
        'statutes_accepted_at' => now(),
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) date('Y'),
        'paid' => false,
    ]);

    apiV1SignedGet('/api/v1/membership/me', ME_CLIENT_KEY, $privkey)['response']
        ->assertOk()
        ->assertJsonPath('data.membership_status', 'awaiting_payment')
        ->assertJsonPath('data.association_status', 'DEFAULT')
        ->assertJsonPath('data.current_year.paid', false);
});

it('reports "member" for a category with the current year paid', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pleb = meMemberFor($privkey, [
        'association_status' => AssociationStatus::PASSIVE,
        'applied_at' => now(),
        'statutes_accepted_at' => now(),
    ]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) date('Y'),
    ]);

    apiV1SignedGet('/api/v1/membership/me', ME_CLIENT_KEY, $privkey)['response']
        ->assertOk()
        ->assertJsonPath('data.membership_status', 'member')
        ->assertJsonPath('data.association_status', 'PASSIVE')
        ->assertJsonPath('data.current_year.paid', true);
});

/*
 * THE CORE CASE OF THIS PHASE.
 *
 * The association deliberately does not implement Art. 4.1 as a hard cut: an
 * active member who lets a year lapse keeps association_status = ACTIVE while
 * ceasing to be a member under the statutes. Anyone reading the enum alone gets
 * this person wrong, in the direction that matters — they would be granted the
 * rights of an active member.
 *
 * The assertion is therefore not "membership_status is lapsed" but "the two
 * fields disagree". A future refactor that quietly derived one from the other
 * would satisfy the first and fail the second.
 */
it('reports "lapsed" while association_status still reads ACTIVE', function () {
    $privkey = (new Key)->generatePrivateKey();
    $paidYear = (int) date('Y');

    $pleb = meMemberFor($privkey, [
        'association_status' => AssociationStatus::ACTIVE,
        'applied_at' => now(),
        'statutes_accepted_at' => now(),
    ]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => $paidYear,
    ]);

    // Into the following year: last year is settled, this year is not.
    $this->travelTo(Carbon::create($paidYear + 1, 3, 14, 12, 0, 0));

    $response = apiV1SignedGet('/api/v1/membership/me', ME_CLIENT_KEY, $privkey)['response'];

    $response->assertOk()
        ->assertJsonPath('data.current_year.year', $paidYear + 1)
        ->assertJsonPath('data.current_year.paid', false);

    expect($response->json('data.association_status'))->toBe('ACTIVE')
        ->and($response->json('data.association_status_value'))->toBe(AssociationStatus::ACTIVE->value)
        ->and($response->json('data.membership_status'))->toBe('lapsed')
        // The database is untouched: no automatic demotion took place.
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::ACTIVE);

    // Stated as its own assertion because it IS the finding: the enum and the
    // derived value make different statements about the same person.
    expect($response->json('data.membership_status'))
        ->not->toBe(strtolower($response->json('data.association_status')));
});

/*
 * ---------------------------------------------------------------------------
 * The subject comes from the signature and from nowhere else.
 * ---------------------------------------------------------------------------
 */

it('ignores a pubkey in the query and answers for the signed one', function () {
    $privkey = (new Key)->generatePrivateKey();
    $mine = meMemberFor($privkey, ['association_status' => AssociationStatus::DEFAULT]);

    // My own pubkey passed along explicitly: accepted, and it changes nothing.
    $call = apiV1SignedGet(
        '/api/v1/membership/me?pubkey='.$mine->pubkey,
        ME_CLIENT_KEY,
        $privkey,
    );

    $call['response']->assertOk()->assertJsonPath('data.pubkey', $mine->pubkey);
});

it('refuses to answer for somebody else named in the query', function () {
    $privkey = (new Key)->generatePrivateKey();
    meMemberFor($privkey);

    $stranger = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::HONORARY,
        'email' => 'stranger@example.test',
    ]);

    $call = apiV1SignedGet(
        '/api/v1/membership/me?pubkey='.$stranger->pubkey,
        ME_CLIENT_KEY,
        $privkey,
    );

    $call['response']->assertForbidden();

    // Not one byte about the stranger travelled — neither their category nor
    // their pubkey nor their address.
    expect($call['response']->getContent())
        ->not->toContain($stranger->pubkey)
        ->not->toContain('HONORARY')
        ->not->toContain('stranger@example.test');
});

/*
 * ---------------------------------------------------------------------------
 * Field allowlist — the response and its error responses.
 * ---------------------------------------------------------------------------
 */

it('returns only the allowed fields and never personal data', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pleb = meMemberFor($privkey, [
        'association_status' => AssociationStatus::ACTIVE,
        'email' => 'private@example.test',
        'application_text' => 'my private application prose',
        'archived_application_text' => 'my archived application prose',
        'applied_at' => now(),
        'statutes_accepted_at' => now(),
    ]);

    $response = apiV1SignedGet('/api/v1/membership/me', ME_CLIENT_KEY, $privkey)['response'];

    $response->assertOk();

    expect($response->json('data'))
        ->toHaveKeys([
            'pubkey',
            'association_status',
            'association_status_value',
            'membership_status',
            'statutes_accepted_at',
            'applied_at',
            'current_year',
        ])
        ->not->toHaveKeys([
            'id',
            'email',
            'application_text',
            'archived_application_text',
            'application_for',
            'no_email',
        ]);

    expect($response->getContent())
        ->not->toContain('private@example.test')
        ->not->toContain('my private application prose')
        ->not->toContain('my archived application prose')
        ->not->toContain('"id"');

    // The internal row id is a sequential counter over every member ever
    // recorded. Handing it out tells a caller how many joined before them.
    expect($response->json('data'))->not->toHaveKey('id')
        ->and($pleb->id)->toBeInt();
});

it('keeps error responses down to a message', function () {
    /*
     * Asserted with debug OFF, because that is the only configuration this
     * assertion is about. With APP_DEBUG=true Laravel adds exception, file,
     * line and trace to every error body — right for local work, and the
     * reason the audit demands APP_DEBUG=false in production (plan, P4 input
     * item 9). Testing the debug shape would pin a contract that must not
     * exist on the live system.
     */
    config(['app.debug' => false]);

    $privkey = (new Key)->generatePrivateKey();
    meMemberFor($privkey, ['email' => 'private@example.test']);

    $stranger = EinundzwanzigPleb::factory()->create(['email' => 'stranger@example.test']);

    $forbidden = apiV1SignedGet(
        '/api/v1/membership/me?pubkey='.$stranger->pubkey,
        ME_CLIENT_KEY,
        $privkey,
    )['response'];

    $unauthorized = $this->withHeaders([
        'X-Api-Key' => ME_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->get('/api/v1/membership/me');

    foreach ([$forbidden, $unauthorized] as $response) {
        expect(array_keys($response->json()))->toBe(['message']);

        expect($response->getContent())
            ->not->toContain('private@example.test')
            ->not->toContain('stranger@example.test')
            ->not->toContain(ME_CLIENT_KEY);
    }
});
