<?php

use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use swentel\nostr\Key\Key;

/*
 * `return_url` on POST /payments/{year}/invoice — where the payer lands after
 * the checkout.
 *
 * The value leaves this application: it becomes `checkout.redirectURL` in the
 * BTCPay payload, and BTCPay's page then sends the payer's browser there. That
 * makes an unchecked value an open redirect wearing the association's domain,
 * which is why the address must be on a server-side allowlist and why an
 * unlisted one is REFUSED rather than silently replaced by the default. A
 * silent fallback would make a probe and a misconfigured deployment look
 * identical — both answer 200, and neither is ever noticed.
 *
 * What the allowlist itself considers equal is pinned in
 * `tests/Feature/InvoiceReturnUrlTest.php`. This file is about the endpoint:
 * that the address reaches BTCPay, that a foreign one costs a 422 and not an
 * invoice, and that a client sending nothing sees exactly what it saw before
 * this field existed.
 */

const RET_CLIENT_KEY = 'ret111111111111111111111111111111111111111111111111111111ret11';

const RET_ALLOWED_URL = 'https://einundzwanzig.group/verein/beitritt';

beforeEach(function () {
    config([
        'einundzwanzig.config.api_client_keys' => ['einundzwanzig-group' => RET_CLIENT_KEY],
        'einundzwanzig.config.membership_fee' => 21000,
        'einundzwanzig.config.currency' => 'SATS',
        'einundzwanzig.config.invoice_return_urls' => [RET_ALLOWED_URL],
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
        'services.btc_pay.store_id' => 'test-store',
        'app.debug' => false,
    ]);

    retFakeBtcPay();
});

/**
 * Fake BTCPay: the invoice is created, the payment methods carry nothing.
 *
 * A fresh factory every time, for the reason spelled out in
 * `MembershipInvoiceTest::invFakeBtcPay()` — `Http::fake()` appends, and the
 * first matching stub answers.
 */
function retFakeBtcPay(): void
{
    Http::swap(new HttpFactory);

    Http::fake([
        'pay.einundzwanzig.space/api/v1/stores/test-store/invoices/*/payment-methods' => Http::response([]),
        'pay.einundzwanzig.space/api/v1/stores/test-store/invoices' => Http::response([
            'id' => 'inv-new',
            'checkoutLink' => 'https://pay.einundzwanzig.space/i/inv-new',
            'status' => 'New',
        ]),
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function retMemberFor(string $privkey, array $attributes = []): EinundzwanzigPleb
{
    $pubkey = (new Key)->getPublicKey($privkey);

    return EinundzwanzigPleb::factory()->create($attributes + [
        'pubkey' => $pubkey,
        'npub' => (new Key)->convertPublicKeyToBech32($pubkey),
    ]);
}

/**
 * @param  array<string, mixed>|null  $body
 * @return array{response: TestResponse, pubkey: string, privkey: string}
 */
function retInvoiceCall(string $privkey, ?array $body = null): array
{
    return apiV1SignedRequest(
        'POST',
        '/api/v1/membership/payments/'.now()->year.'/invoice',
        RET_CLIENT_KEY,
        $privkey,
        $body,
    );
}

/**
 * The `checkout.redirectURL` of the invoice that was ordered at BTCPay.
 */
function retSentRedirectUrl(): ?string
{
    $orders = Http::recorded(
        fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://pay.einundzwanzig.space/api/v1/stores/test-store/invoices'
    );

    expect($orders)->toHaveCount(1);

    /** @var ClientRequest $request */
    $request = $orders[0][0];

    return $request->data()['checkout']['redirectURL'] ?? null;
}

it('sends an allowed return address to BTCPay', function () {
    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    retInvoiceCall($privkey, ['return_url' => RET_ALLOWED_URL])['response']
        ->assertOk()
        ->assertJsonPath('data.created', true);

    expect(retSentRedirectUrl())->toBe(RET_ALLOWED_URL);
});

it('keeps the association profile page when no return address is sent', function () {
    /*
     * THE UNCHANGED CASE, and the one that matters most: every caller that
     * existed before this field must see byte for byte what it saw before.
     */
    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    retInvoiceCall($privkey)['response']->assertOk();

    expect(retSentRedirectUrl())->toBe(route('association.profile'));
});

it('keeps the association profile page when the return address is explicitly null', function () {
    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    retInvoiceCall($privkey, ['return_url' => null])['response']->assertOk();

    expect(retSentRedirectUrl())->toBe(route('association.profile'));
});

it('refuses a return address that is not on the allowlist and orders no invoice', function (string $candidate) {
    /*
     * 422, NOT A QUIET FALLBACK. And nothing is spent at BTCPay: the refusal
     * happens in the form request, before the controller runs, so a probe
     * cannot burn the association's invoice quota either.
     */
    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    $call = retInvoiceCall($privkey, ['return_url' => $candidate]);

    $call['response']->assertStatus(422)
        ->assertJsonValidationErrors('return_url');

    Http::assertNothingSent();

    expect(PaymentEvent::query()->value('btc_pay_invoice'))->toBeNull();
})->with([
    'a foreign host' => 'https://angreifer.tld/verein/beitritt',
    'the allowed host as a prefix of another' => 'https://einundzwanzig.group.angreifer.tld/verein/beitritt',
    'the allowed host as userinfo' => 'https://einundzwanzig.group@angreifer.tld/verein/beitritt',
    'a foreign port' => 'https://einundzwanzig.group:8443/verein/beitritt',
    'a foreign path' => 'https://einundzwanzig.group/admin',
    'a javascript url' => 'javascript:alert(1)',
    'a data url' => 'data:text/html,<script>alert(1)</script>',
    'a relative path' => '/verein/beitritt',
]);

it('treats an empty return address as no return address, not as a bypass', function () {
    /*
     * MEASURED, NOT CHOSEN: Laravel's `ConvertEmptyStringsToNull` middleware
     * runs before validation, so by the time the form request sees the body an
     * empty `return_url` is indistinguishable from an absent one. Pinned here
     * rather than left implicit, because the question it raises is a fair one
     * — is this the silent fallback the 422 above exists to prevent?
     *
     * It is not. A silent fallback is dangerous when it hides an address that
     * was REFUSED; an empty string names no address to refuse, and what goes
     * to BTCPay is the association's own profile page. Nothing a client sent
     * ends up in `redirectURL`, which is the property that matters.
     */
    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    retInvoiceCall($privkey, ['return_url' => ''])['response']->assertOk();

    expect(retSentRedirectUrl())->toBe(route('association.profile'));
});

it('refuses every return address when the allowlist is empty', function () {
    /*
     * Fail-closed on a deployment that never configured the list. Refusing is
     * noisy and therefore gets fixed; the opposite is silent and is an open
     * redirect.
     */
    config(['einundzwanzig.config.invoice_return_urls' => []]);

    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    retInvoiceCall($privkey, ['return_url' => RET_ALLOWED_URL])['response']
        ->assertStatus(422)
        ->assertJsonValidationErrors('return_url');

    Http::assertNothingSent();
});

it('checks the return address on the idempotent repeat as well', function () {
    /*
     * The address cannot take effect on a repeat — there is no new BTCPay
     * payload to put it in — but it is still refused. A client must never
     * learn that an address the association rejects is accepted whenever an
     * invoice happens to exist already: that is the state in which a probe
     * would be told "fine" and would keep probing.
     */
    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    retInvoiceCall($privkey)['response']->assertOk()->assertJsonPath('data.created', true);

    retFakeBtcPay();

    retInvoiceCall($privkey, ['return_url' => 'https://angreifer.tld/'])['response']
        ->assertStatus(422)
        ->assertJsonValidationErrors('return_url');

    Http::assertNothingSent();
});

it('leaves the redirect of an existing invoice alone on the idempotent repeat', function () {
    /*
     * The honest consequence of idempotency: the redirect belongs to the
     * invoice that already exists at BTCPay, and a second call does not
     * rewrite it. Documented on the endpoint so that a client which needs a
     * different return address knows it needs a different invoice.
     */
    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    retInvoiceCall($privkey)['response']->assertOk()->assertJsonPath('data.created', true);

    expect(retSentRedirectUrl())->toBe(route('association.profile'));

    retFakeBtcPay();

    retInvoiceCall($privkey, ['return_url' => RET_ALLOWED_URL])['response']
        ->assertOk()
        ->assertJsonPath('data.created', false);

    // No second invoice was ordered, so no redirect was rewritten either.
    $orders = Http::recorded(fn (ClientRequest $request): bool => $request->method() === 'POST');

    expect($orders)->toHaveCount(0)
        ->and(PaymentEvent::query()->count())->toBe(1);
});

it('still ignores amount, currency and year next to a valid return address', function () {
    /*
     * One field became readable, not the body. Amount and currency come from
     * the configuration and the fee year from the path; a body naming them
     * changes nothing, and adding `return_url` did not open a door for them.
     */
    $privkey = (new Key)->generatePrivateKey();
    retMemberFor($privkey);

    retInvoiceCall($privkey, [
        'return_url' => RET_ALLOWED_URL,
        'amount' => 1,
        'currency' => 'BTC',
        'year' => 1970,
    ])['response']
        ->assertOk()
        ->assertJsonPath('data.payment.year', (int) now()->year)
        ->assertJsonPath('data.payment.amount', 21000)
        ->assertJsonPath('data.payment.currency', 'SATS');

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'POST'
        && $request['amount'] === 21000
        && $request['currency'] === 'SATS');
});
