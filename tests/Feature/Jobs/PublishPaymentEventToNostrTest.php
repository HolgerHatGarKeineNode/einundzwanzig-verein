<?php

use App\Jobs\PublishPaymentEventToNostr;
use App\Models\EinundzwanzigPleb;
use App\Services\MembershipService;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use swentel\nostr\Key\Key;

it('queues the kind-32121 publication instead of running it in the request', function () {
    Queue::fake();

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $paymentEvent = app(MembershipService::class)->resolvePaymentEvent($pleb, (int) date('Y'));

    Queue::assertPushed(
        PublishPaymentEventToNostr::class,
        fn (PublishPaymentEventToNostr $job) => $job->paymentEventId === $paymentEvent->id,
    );

    // The record exists before the relay has been talked to at all.
    expect($paymentEvent->exists)->toBeTrue()
        ->and($paymentEvent->event_id)->toBeNull();
});

it('creates an invoice even though no relay can be reached', function () {
    config()->set('services.btc_pay.base_url', 'https://btcpay.test');
    config()->set('services.relay', '');

    Http::fake([
        'btcpay.test/*' => Http::response([
            'id' => 'invoice-no-relay',
            'checkoutLink' => 'https://btcpay.test/i/invoice-no-relay',
        ], 200),
    ]);

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.profile')
        ->call('pay', date('Y').':'.$pleb->pubkey)
        ->assertRedirect('https://btcpay.test/i/invoice-no-relay');

    expect($pleb->paymentEvents()->where('year', date('Y'))->first()->btc_pay_invoice)
        ->toBe('invoice-no-relay');
});

it('never overwrites an event id that is already set', function () {
    /*
     * The relay URL is invalid on purpose and the signing key is real: if the
     * job did not bail out on an existing event id, it would sign the note and
     * then throw in the Relay constructor. Green here therefore means the guard
     * held, not that the relay was merely unconfigured.
     */
    config()->set('services.relay', 'http://relay.invalid');
    config()->set('services.nostr', (new Key)->generatePrivateKey());

    $pleb = EinundzwanzigPleb::factory()->active()->create();

    $paymentEvent = $pleb->paymentEvents()->create([
        'year' => (int) date('Y'),
        'amount' => 21000,
        'event_id' => 'already-published',
    ]);

    (new PublishPaymentEventToNostr($paymentEvent->id))->handle();
    (new PublishPaymentEventToNostr($paymentEvent->id))->handle();

    expect($paymentEvent->fresh()->event_id)->toBe('already-published');
});

it('does nothing when the payment event has been deleted meanwhile', function () {
    config()->set('services.relay', 'http://relay.invalid');

    (new PublishPaymentEventToNostr(999999))->handle();
})->throwsNoExceptions();
