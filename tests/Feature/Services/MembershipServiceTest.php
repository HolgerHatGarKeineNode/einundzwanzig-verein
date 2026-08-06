<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use App\Services\MembershipService;
use Illuminate\Support\Facades\DB;

function paidEventFor(EinundzwanzigPleb $pleb): PaymentEvent
{
    return $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'paid' => true,
        'event_id' => 'event-'.fake()->uuid(),
        'btc_pay_invoice' => 'invoice-'.fake()->uuid(),
    ]);
}

it('raises a DEFAULT member to PASSIVE on a paid fee', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    app(MembershipService::class)->grantMembershipOnPayment(paidEventFor($pleb));

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);
});

it('leaves an ACTIVE member untouched', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::ACTIVE,
        'statutes_accepted_at' => now(),
    ]);

    app(MembershipService::class)->grantMembershipOnPayment(paidEventFor($pleb));

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::ACTIVE)
        ->and($pleb->membershipGrants()->count())->toBe(0);
});

it('leaves an HONORARY member untouched', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::HONORARY,
        'statutes_accepted_at' => now(),
    ]);

    app(MembershipService::class)->grantMembershipOnPayment(paidEventFor($pleb));

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::HONORARY)
        ->and($pleb->membershipGrants()->count())->toBe(0);
});

it('grants nothing without a recorded consent to the statutes', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => null,
    ]);

    app(MembershipService::class)->grantMembershipOnPayment(paidEventFor($pleb));

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and($pleb->membershipGrants()->count())->toBe(0);
});

it('grants nothing while the fee is unpaid', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    $unpaid = $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'paid' => false,
    ]);

    app(MembershipService::class)->grantMembershipOnPayment($unpaid);

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and($pleb->membershipGrants()->count())->toBe(0);
});

it('is idempotent — a second call changes nothing', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    $paymentEvent = paidEventFor($pleb);
    $service = app(MembershipService::class);

    $service->grantMembershipOnPayment($paymentEvent);
    $service->grantMembershipOnPayment($paymentEvent);
    $service->grantMembershipOnPayment($paymentEvent->fresh());

    expect($pleb->fresh()->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and($pleb->membershipGrants()->count())->toBe(1);
});

it('records which payment event triggered the membership, answerable from the database', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    $paymentEvent = paidEventFor($pleb);

    app(MembershipService::class)->grantMembershipOnPayment($paymentEvent);

    $row = DB::table('membership_grants')
        ->where('einundzwanzig_pleb_id', $pleb->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->payment_event_id)->toBe($paymentEvent->id)
        ->and((int) $row->from_status)->toBe(AssociationStatus::DEFAULT->value)
        ->and((int) $row->to_status)->toBe(AssociationStatus::PASSIVE->value)
        ->and((int) $row->year)->toBe((int) date('Y'))
        ->and($row->granted_at)->not->toBeNull();

    expect($pleb->membershipGrants()->first()->paymentEvent->is($paymentEvent))->toBeTrue();
});

it('books a settled payment and grants the membership in one step', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => 'invoice-settled',
    ]);

    app(MembershipService::class)->markPaid($paymentEvent);

    expect((bool) $paymentEvent->fresh()->paid)->toBeTrue()
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);
});

it('never deletes a paid payment event when the invoice expires', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();
    $paymentEvent = paidEventFor($pleb);

    $result = app(MembershipService::class)->releaseExpiredInvoice($paymentEvent);

    expect($result['released'])->toBeFalse()
        ->and(PaymentEvent::query()->whereKey($paymentEvent->id)->exists())->toBeTrue()
        ->and((bool) $paymentEvent->fresh()->paid)->toBeTrue()
        ->and($paymentEvent->fresh()->btc_pay_invoice)->toBe($paymentEvent->btc_pay_invoice);
});

it('releases an unpaid expired invoice so a new payment can start', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => 'invoice-expired',
    ]);

    $result = app(MembershipService::class)->releaseExpiredInvoice($paymentEvent);

    expect($result['released'])->toBeTrue()
        ->and(PaymentEvent::query()->whereKey($paymentEvent->id)->exists())->toBeFalse()
        ->and($result['payment_event']->btc_pay_invoice)->toBeNull()
        ->and($pleb->paymentEvents()->where('year', (int) date('Y'))->count())->toBe(1);
});

it('records an application without touching the association status', function () {
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
    ]);

    app(MembershipService::class)->apply($pleb);

    $fresh = $pleb->fresh();

    expect($fresh->applied_at)->not->toBeNull()
        ->and($fresh->statutes_accepted_at)->not->toBeNull()
        ->and($fresh->association_status)->toBe(AssociationStatus::DEFAULT);
});

it('never overwrites an existing consent timestamp on a second application', function () {
    $accepted = now()->subYears(2)->startOfSecond();

    $pleb = EinundzwanzigPleb::factory()->create([
        'statutes_accepted_at' => $accepted,
    ]);

    app(MembershipService::class)->apply($pleb);

    expect($pleb->fresh()->statutes_accepted_at->timestamp)->toBe($accepted->timestamp);
});

it('reports fee and currency from the config in the status', function () {
    config()->set('einundzwanzig.config.membership_fee', 4242);
    config()->set('einundzwanzig.config.currency', 'SATS');

    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

    $status = app(MembershipService::class)->status($pleb);

    expect($status['fee'])->toBe(4242)
        ->and($status['currency'])->toBe('SATS')
        ->and($status['paid'])->toBeTrue()
        ->and($status['is_member'])->toBeTrue()
        ->and($status['year'])->toBe((int) date('Y'));
});
