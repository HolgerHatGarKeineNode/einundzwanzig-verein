<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

function fakeBtcPayStatus(string $status): void
{
    config()->set('services.btc_pay.base_url', 'https://btcpay.test');

    Http::fake([
        'btcpay.test/*' => Http::response([
            'id' => 'invoice-under-test',
            'status' => $status,
            'expirationTime' => now()->subMinutes(5)->toIso8601String(),
        ], 200),
    ]);
}

it('keeps a paid payment event when btcpay reports the invoice as expired', function () {
    fakeBtcPayStatus('Expired');

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'paid' => true,
        'event_id' => 'event-paid',
        'btc_pay_invoice' => 'invoice-under-test',
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSet('invoiceStatus', 'Expired')
        ->assertSet('currentYearIsPaid', true);

    expect(PaymentEvent::query()->whereKey($paymentEvent->id)->exists())->toBeTrue()
        ->and((bool) $paymentEvent->fresh()->paid)->toBeTrue()
        ->and($paymentEvent->fresh()->btc_pay_invoice)->toBe('invoice-under-test');
});

it('keeps a paid payment event when btcpay reports the invoice as invalid', function () {
    fakeBtcPayStatus('Invalid');

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'paid' => true,
        'event_id' => 'event-paid',
        'btc_pay_invoice' => 'invoice-under-test',
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')->assertSet('invoiceStatus', 'Invalid');

    expect(PaymentEvent::query()->whereKey($paymentEvent->id)->exists())->toBeTrue()
        ->and((bool) $paymentEvent->fresh()->paid)->toBeTrue();
});

it('still drops an unpaid expired invoice — the counter-check', function () {
    fakeBtcPayStatus('Expired');

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'paid' => false,
        'event_id' => 'event-unpaid',
        'btc_pay_invoice' => 'invoice-under-test',
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->assertSet('invoiceStatus', 'Expired')
        ->assertSet('invoiceStatusVariant', 'warning');

    expect(PaymentEvent::query()->whereKey($paymentEvent->id)->exists())->toBeFalse()
        ->and($pleb->paymentEvents()->where('year', date('Y'))->count())->toBe(1)
        ->and($pleb->paymentEvents()->where('year', date('Y'))->first()->btc_pay_invoice)->toBeNull();
});

it('makes an applicant a passive member once btcpay reports the invoice settled', function () {
    fakeBtcPayStatus('Settled');

    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
        'applied_at' => now(),
    ]);

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'paid' => false,
        'event_id' => 'event-settling',
        'btc_pay_invoice' => 'invoice-under-test',
    ]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')->assertSet('currentYearIsPaid', true);

    expect((bool) $paymentEvent->fresh()->paid)->toBeTrue()
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and($pleb->membershipGrants()->first()->payment_event_id)->toBe($paymentEvent->id);
});

it('takes the displayed fee from the config, not from the environment name', function () {
    config()->set('einundzwanzig.config.membership_fee', 4242);

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')->assertSet('amountToPay', 4242);

    expect((int) $pleb->paymentEvents()->where('year', date('Y'))->first()->amount)->toBe(4242);
});
