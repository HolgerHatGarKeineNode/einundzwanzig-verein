<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use swentel\nostr\Key\Key;

const PAY_CLIENT_KEY = 'pay11111111111111111111111111111111111111111111111111111111pay1';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => PAY_CLIENT_KEY],
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function payMemberFor(string $privkey, array $attributes = []): EinundzwanzigPleb
{
    $pubkey = (new Key)->getPublicKey($privkey);

    return EinundzwanzigPleb::factory()->create($attributes + [
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);
}

it('requires a NIP-98 signature', function () {
    $this->withHeaders([
        'X-Api-Key' => PAY_CLIENT_KEY,
        'Accept' => 'application/json',
    ])->get('/api/v1/membership/payments')->assertUnauthorized();
});

it('returns the caller’s own fee history, newest year first', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pleb = payMemberFor($privkey, ['association_status' => AssociationStatus::ACTIVE]);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => 2024,
        'amount' => 21000,
        'btc_pay_invoice' => 'inv-2024',
    ]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => 2025,
        'amount' => 21000,
        'btc_pay_invoice' => 'inv-2025',
        'paid' => false,
    ]);

    $response = apiV1SignedGet('/api/v1/membership/payments', PAY_CLIENT_KEY, $privkey)['response'];

    $response->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.year'))->toBe(2025)
        ->and($response->json('data.0.paid'))->toBeFalse()
        // No receipt for an unsettled invoice — that link would be a checkout
        // page wearing a receipt's name.
        ->and($response->json('data.0.receipt_url'))->toBeNull()
        ->and($response->json('data.1.year'))->toBe(2024)
        ->and($response->json('data.1.paid'))->toBeTrue()
        ->and($response->json('data.1.receipt_url'))
        ->toBe('https://pay.einundzwanzig.space/i/inv-2024/receipt');
});

it('never returns another member’s payments', function () {
    $privkey = (new Key)->generatePrivateKey();
    $mine = payMemberFor($privkey);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $mine->id,
        'year' => 2025,
        'amount' => 21000,
    ]);

    $stranger = EinundzwanzigPleb::factory()->create(['email' => 'stranger@example.test']);

    PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $stranger->id,
        'year' => 2025,
        'amount' => 999999,
        'btc_pay_invoice' => 'stranger-invoice',
    ]);

    $response = apiV1SignedGet('/api/v1/membership/payments', PAY_CLIENT_KEY, $privkey)['response'];

    $response->assertOk();

    // One record, and it is mine. No endpoint on this surface returns a second
    // member's data — not even hidden in a list.
    expect($response->json('data'))->toHaveCount(1);

    expect($response->getContent())
        ->not->toContain('stranger-invoice')
        ->not->toContain('999999')
        ->not->toContain('stranger@example.test');
});

it('returns an empty collection for a pubkey without a record', function () {
    $call = apiV1SignedGet('/api/v1/membership/payments', PAY_CLIENT_KEY);

    $call['response']->assertOk()->assertJsonPath('data', []);

    expect(EinundzwanzigPleb::where('pubkey', $call['pubkey'])->exists())->toBeFalse();
});

it('returns only the allowed fields and never personal data', function () {
    $privkey = (new Key)->generatePrivateKey();
    $pleb = payMemberFor($privkey, [
        'email' => 'private@example.test',
        'application_text' => 'my private application prose',
        'archived_application_text' => 'my archived application prose',
    ]);

    $payment = PaymentEvent::factory()->paid()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => 2025,
        'amount' => 21000,
        'btc_pay_invoice' => 'inv-2025',
        'event_id' => 'nostr-event-id-of-the-payment',
    ]);

    $response = apiV1SignedGet('/api/v1/membership/payments', PAY_CLIENT_KEY, $privkey)['response'];

    $response->assertOk();

    expect($response->json('data.0'))
        ->toHaveKeys(['year', 'amount', 'currency', 'paid', 'receipt_url'])
        ->not->toHaveKeys([
            'id',
            'einundzwanzig_pleb_id',
            'btc_pay_invoice',
            'event_id',
            'email',
            'application_text',
            'archived_application_text',
        ]);

    expect($response->getContent())
        ->not->toContain('private@example.test')
        ->not->toContain('my private application prose')
        ->not->toContain('my archived application prose')
        ->not->toContain('nostr-event-id-of-the-payment')
        ->and($payment->id)->toBeInt();
});

it('keeps error responses down to a message', function () {
    config(['app.debug' => false]);

    $privkey = (new Key)->generatePrivateKey();
    payMemberFor($privkey, ['email' => 'private@example.test']);

    $stranger = EinundzwanzigPleb::factory()->create(['email' => 'stranger@example.test']);

    $forbidden = apiV1SignedGet(
        '/api/v1/membership/payments?pubkey='.$stranger->pubkey,
        PAY_CLIENT_KEY,
        $privkey,
    )['response'];

    $forbidden->assertForbidden();

    expect(array_keys($forbidden->json()))->toBe(['message']);

    expect($forbidden->getContent())
        ->not->toContain('private@example.test')
        ->not->toContain('stranger@example.test')
        ->not->toContain(PAY_CLIENT_KEY);
});
