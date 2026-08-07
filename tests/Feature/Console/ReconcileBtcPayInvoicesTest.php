<?php

use App\Enums\AssociationStatus;
use App\Enums\PaymentReviewReason;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use App\Models\PaymentReview;
use App\Services\MembershipService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * The reconciliation command closes the two gaps that nothing in this database
 * can even see: an invoice that exists at BTCPay and nowhere here, and the
 * personal data left in the invoices of a member who has been erased (the
 * erasure drops this side's pointer, so there is provably no route from here
 * to there afterwards).
 */

beforeEach(function () {
    config([
        'einundzwanzig.config.membership_fee' => 21000,
        'einundzwanzig.config.currency' => 'SATS',
        'services.btc_pay.base_url' => 'https://pay.einundzwanzig.space',
        'services.btc_pay.store_id' => 'test-store',
    ]);
});

/**
 * Fake the two BTCPay calls this command makes, replacing anything faked
 * before — `Http::fake()` appends and the first stub wins, so a second fake
 * for the same host would be silently ignored (measured in P4; see
 * `invFakeBtcPay()` in tests/Feature/Api/V1/MembershipInvoiceTest.php).
 *
 * @param  list<array<string, mixed>>  $invoices
 */
function reconcileFake(array $invoices, ?callable $listResponse = null): void
{
    Http::swap(new HttpFactory);

    $byId = collect($invoices)->keyBy('id');

    Http::fake(function (ClientRequest $request) use ($byId, $listResponse) {
        $url = $request->url();

        // PUT .../invoices/{id} — the metadata update.
        if ($request->method() === 'PUT') {
            return Http::response(['id' => 'updated']);
        }

        // GET .../invoices/{id} — a single invoice.
        if (preg_match('#/invoices/([^/?]+)#', $url, $matches) === 1) {
            return Http::response($byId->get($matches[1], []));
        }

        if ($listResponse) {
            return $listResponse();
        }

        // GET .../invoices — the listing, honouring skip/take like BTCPay does.
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return Http::response(
            $byId->values()->slice((int) ($query['skip'] ?? 0), (int) ($query['take'] ?? 100))->values()->all()
        );
    });
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function reconcileInvoice(string $id, string $pubkey, array $overrides = []): array
{
    return $overrides + [
        'id' => $id,
        'status' => 'Settled',
        'amount' => '21000',
        'currency' => 'SATS',
        'createdTime' => now()->timestamp,
        'metadata' => [
            'itemDesc' => 'Mitgliedsbeitrag '.now()->year.' von nostr:npub1whatever',
            'orderId' => 'order-1',
            'orderUrl' => url()->route('association.profile'),
            'posData' => [
                'event' => 'ev-1',
                'pubkey' => $pubkey,
                'npub' => 'npub1whatever',
                'year' => (int) now()->year,
                // The marker that says "this is one of ours". Without it an
                // invoice is left alone, however much it looks like a fee.
                'source' => MembershipService::INVOICE_SOURCE,
            ],
        ],
    ];
}

it('adopts an orphaned invoice and books it through the same gate', function () {
    /*
     * The orphan is real, not hypothetical: invoice creation writes at BTCPay
     * and here, and a rolled-back transaction on this side leaves the checkout
     * standing over there. Pay it and money arrives against no claim.
     */
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
        'applied_at' => now(),
    ]);

    $payment = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => null,
    ]);

    reconcileFake([reconcileInvoice('inv-orphan', $pleb->pubkey)]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    expect($payment->fresh()->btc_pay_invoice)->toBe('inv-orphan')
        ->and((bool) $payment->fresh()->paid)->toBeTrue()
        // Booked through markPaid(), so the promotion rules applied in full.
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::PASSIVE)
        ->and(MembershipGrant::query()->where('payment_event_id', $payment->id)->count())->toBe(1);
});

it('adopts an orphan but refuses to book it for the wrong amount', function () {
    /*
     * The discriminating counterpart to the test above: adoption must not be a
     * back door around the amount check. Same gate, so the same refusal.
     */
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    $payment = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => null,
    ]);

    reconcileFake([reconcileInvoice('inv-cheap', $pleb->pubkey, ['amount' => '1'])]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    expect((bool) $payment->fresh()->paid)->toBeFalse()
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::DEFAULT)
        ->and(PaymentReview::query()->where('reason', PaymentReviewReason::AmountMismatch->value)->count())->toBe(1);
});

it('flags a settled invoice it cannot match to anybody', function () {
    reconcileFake([reconcileInvoice('inv-stray', str_repeat('a', 64))]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    expect(PaymentReview::query()
        ->where('btc_pay_invoice', 'inv-stray')
        ->where('reason', PaymentReviewReason::UnknownInvoice->value)
        ->where('source', 'reconcile')
        ->count())->toBe(1);
});

it('strips the person out of the invoices of an erased member', function () {
    /*
     * Residual gap 1 of `erasePersonalData()`, closed. That method deliberately
     * does NOT make this call — an erasure must not fail because a payment
     * provider is unreachable — and it clears `btc_pay_invoice`, so afterwards
     * NOTHING in this database leads to the invoice that still carries the
     * pubkey, the npub and a naming itemDesc. The only way in is from the
     * BTCPay side, which is what this command does.
     */
    $pleb = EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]);
    $pubkey = $pleb->pubkey;

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => true,
        'btc_pay_invoice' => 'inv-erased',
    ]);

    app(MembershipService::class)->erasePersonalData($pleb);

    // Precondition of the whole exercise: no route left from here to there.
    expect(PaymentEvent::query()->where('btc_pay_invoice', 'inv-erased')->exists())->toBeFalse();

    reconcileFake([reconcileInvoice('inv-erased', $pubkey)]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    Http::assertSent(function (ClientRequest $request): bool {
        if ($request->method() !== 'PUT') {
            return false;
        }

        $metadata = $request->data()['metadata'] ?? [];

        return ! array_key_exists('pubkey', $metadata['posData'] ?? [])
            && ! array_key_exists('npub', $metadata['posData'] ?? [])
            && ! str_contains((string) ($metadata['itemDesc'] ?? ''), 'npub')
            // The fee year is not a personal reference and stays, so a later
            // run can still tell which year the invoice belonged to.
            && ($metadata['posData']['year'] ?? null) === (int) now()->year;
    });
});

it('leaves a living member´s invoice metadata alone', function () {
    /*
     * The discriminating counterpart. A command that scrubbed every invoice it
     * saw would pass the test above and quietly destroy the metadata of every
     * paying member.
     */
    $pleb = EinundzwanzigPleb::factory()->create();

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => true,
        'btc_pay_invoice' => 'inv-live',
    ]);

    reconcileFake([reconcileInvoice('inv-live', $pleb->pubkey)]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'PUT');
});

it('stays repeatable when BTCPay cannot be reached', function () {
    /*
     * Nothing is half-written and nothing throws — but the run REPORTS the
     * failure (see the separate exit-code test). What this one is about is the
     * other half: the work is not lost, it is waiting.
     *
     * The second half of the test is what makes the first half mean something:
     * the very same work succeeds on the next run, untouched.
     */
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    $payment = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => null,
    ]);

    reconcileFake(
        [reconcileInvoice('inv-orphan', $pleb->pubkey)],
        listResponse: fn () => throw new ConnectionException('Connection timed out'),
    );

    $this->artisan('membership:reconcile-btcpay')->assertFailed();

    expect($payment->fresh()->btc_pay_invoice)->toBeNull()
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::DEFAULT);

    // Same command, BTCPay back up — the work was waiting, not lost.
    reconcileFake([reconcileInvoice('inv-orphan', $pleb->pubkey)]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    expect($payment->fresh()->btc_pay_invoice)->toBe('inv-orphan')
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);
});

it('settles a pending invoice the webhook never reported', function () {
    /*
     * The webhook comes from outside and a delivery can be lost. Then somebody
     * has paid and is not a member, and nobody finds out — which is the whole
     * reason this command also sweeps the fees that are on record as unpaid.
     */
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::DEFAULT,
        'statutes_accepted_at' => now(),
    ]);

    $payment = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => 'inv-pending',
    ]);

    reconcileFake([reconcileInvoice('inv-pending', $pleb->pubkey)]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    expect((bool) $payment->fresh()->paid)->toBeTrue()
        ->and($pleb->fresh()->association_status)->toBe(AssociationStatus::PASSIVE);
});

it('writes nothing on a dry run', function () {
    $pleb = EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => null,
    ]);

    reconcileFake([reconcileInvoice('inv-orphan', $pleb->pubkey)]);

    $this->artisan('membership:reconcile-btcpay', ['--dry-run' => true])->assertSuccessful();

    expect(PaymentEvent::query()->whereNotNull('btc_pay_invoice')->count())->toBe(0)
        ->and(PaymentReview::query()->count())->toBe(0);
});

it('walks past the first page of invoices', function () {
    /*
     * WHY THIS IS NOT TIDINESS. A single capped request makes the
     * depersonalisation guarantee quietly false: an erased member whose
     * invoice fell outside page one would keep their pubkey and npub at BTCPay
     * forever, and every run would still report success. The invoice that has
     * to be scrubbed here sits on the SECOND page, so a command that fetches
     * once fails this test and passes every other one in the file.
     */
    $erased = EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]);
    $erasedPubkey = $erased->pubkey;
    app(MembershipService::class)->erasePersonalData($erased);

    $living = EinundzwanzigPleb::factory()->create();

    reconcileFake([
        reconcileInvoice('inv-page-1', $living->pubkey),
        reconcileInvoice('inv-page-2', $erasedPubkey),
    ]);

    $this->artisan('membership:reconcile-btcpay', ['--page-size' => 1])->assertSuccessful();

    Http::assertSent(function (ClientRequest $request): bool {
        return $request->method() === 'PUT' && str_contains($request->url(), 'inv-page-2');
    });
});

it('does not destroy the Storno on a routine unattended run', function () {
    /*
     * F1 again, through the door that needs no human at all. `settlePendingInvoices()`
     * selects on `paid = false` plus an invoice — which is exactly what a
     * reversed fee looks like — and sends it through the same branch.
     */
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::PASSIVE,
        'statutes_accepted_at' => now(),
    ]);

    $payment = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => true,
        'btc_pay_invoice' => 'inv-reversed',
    ]);

    app(MembershipService::class)->reversePayment($payment, 'InvoiceInvalid', 'test');

    reconcileFake([reconcileInvoice('inv-reversed', $pleb->pubkey, ['status' => 'Invalid'])]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    expect(PaymentEvent::query()->find($payment->id))->not->toBeNull()
        ->and(DB::table('payment_reversals')->count())->toBe(1);
});

it('leaves the amount of a past fee year untouched', function () {
    /*
     * F2. `settlePendingInvoices()` had no year limit, so an old unpaid fee
     * with a dead invoice was released and re-created — at TODAY's fee. That
     * destroys the very reference `verifyPayment()` compares against, and the
     * docblock there justifies using the stored amount precisely because the
     * general assembly sets the fee per year.
     */
    $pleb = EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]);

    $old = PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year - 3,
        'amount' => 15000,
        'paid' => false,
        'event_id' => 'ev-2023',
        'btc_pay_invoice' => 'inv-2023',
    ]);

    reconcileFake([reconcileInvoice('inv-2023', $pleb->pubkey, ['status' => 'Expired'])]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    $fresh = PaymentEvent::query()->find($old->id);

    expect($fresh)->not->toBeNull()
        ->and((int) $fresh->amount)->toBe(15000)
        ->and($fresh->event_id)->toBe('ev-2023');
});

it('does not touch an invoice that is not a membership fee', function () {
    /*
     * F3, with the payload the auditor measured. A 64-hex value in
     * `posData.pubkey` is not a marker of ownership — anything can put one
     * there, and a BTCPay store may serve more than one integration. The
     * measured run rewrote a foreign booking's metadata and replaced its
     * description with "Mitgliedsbeitrag (Mitglied geloescht)".
     */
    reconcileFake([[
        'id' => 'inv-foreign',
        'status' => 'Settled',
        'amount' => '2500',
        'currency' => 'SATS',
        'createdTime' => now()->timestamp,
        'metadata' => [
            'itemDesc' => 'Meetup-Ticket Nr. 77',
            'orderId' => 'meetup-ticket-77',
            'posData' => ['pubkey' => str_repeat('b', 64), 'seat' => '12'],
        ],
    ]]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'PUT');

    // And it is not filed as a membership orphan either — it never was one.
    expect(PaymentReview::query()->count())->toBe(0);
});

it('refuses to adopt an invoice whose fee year cannot be established', function () {
    $pleb = EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]);

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => null,
    ]);

    $invoice = reconcileInvoice('inv-yearless', $pleb->pubkey);
    unset($invoice['createdTime'], $invoice['metadata']['posData']['year']);

    reconcileFake([$invoice]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    // Falling back to "this year" would file an invoice of unknown vintage
    // against the current fee.
    expect(PaymentEvent::query()->whereNotNull('btc_pay_invoice')->count())->toBe(0);
});

it('refuses a posData year outside the plausible range', function () {
    $pleb = EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]);

    // A matching fee year exists, so the ONLY thing standing between this
    // invoice and adoption is whether 1970 is believed.
    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => 1970,
        'amount' => 21000,
        'paid' => false,
        'btc_pay_invoice' => null,
    ]);

    $invoice = reconcileInvoice('inv-1970', $pleb->pubkey);
    $invoice['metadata']['posData']['year'] = 1970;
    unset($invoice['createdTime']);

    reconcileFake([$invoice]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    expect(PaymentEvent::query()->whereNotNull('btc_pay_invoice')->count())->toBe(0);
});

it('exits non-zero when BTCPay could not be reached', function () {
    /*
     * Reversed from the first draft of this phase, on the auditor's finding.
     * "Watch the log line, not the status" is not a guarantee — a monitor
     * watching exit codes would report a provider that has been down for a
     * week as healthy. The run still does not THROW and still stays
     * repeatable; only the verdict it reports changes.
     */
    reconcileFake([], listResponse: fn () => throw new ConnectionException('Connection timed out'));

    $this->artisan('membership:reconcile-btcpay')->assertFailed();
});

it('recognises a pre-marker invoice however APP_URL happened to be written', function (string $variant) {
    /*
     * R2. The legacy branch compared `metadata.orderUrl` byte for byte against
     * `url()->route('association.profile')`. The stored value was written by
     * whatever APP_URL was configured on the day the invoice was created, and
     * the plan already records APP_URL as a fragile operating parameter. Each
     * of these three variants made the run report "0 depersonalised" and exit
     * 0 — a hole in the erasure promise that announced itself as success.
     */
    $pleb = EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]);
    $pubkey = $pleb->pubkey;

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => true,
        'btc_pay_invoice' => 'inv-legacy',
    ]);

    app(MembershipService::class)->erasePersonalData($pleb);

    /*
     * Built here rather than in the dataset: a dataset closure is evaluated
     * while Pest collects tests, long before the application is booted, so
     * url()->route() is not resolvable there.
     */
    $route = url()->route('association.profile');
    $parts = parse_url($route);
    $path = $parts['path'] ?? '';

    $orderUrl = match ($variant) {
        'identical' => $route,
        'www prefix' => $parts['scheme'].'://www.'.$parts['host'].$path,
        'other scheme' => ($parts['scheme'] === 'https' ? 'http' : 'https').'://'.$parts['host'].$path,
        'trailing slash' => rtrim($route, '/').'/',
    };

    // A pre-marker invoice: no posData.source, so only orderUrl can identify it.
    $invoice = reconcileInvoice('inv-legacy', $pubkey);
    unset($invoice['metadata']['posData']['source']);
    $invoice['metadata']['orderUrl'] = $orderUrl;

    reconcileFake([$invoice]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'PUT');
})->with(['identical', 'www prefix', 'other scheme', 'trailing slash']);

it('does not mistake a foreign host for our own profile route', function () {
    /*
     * The discriminating counterpart: loosening the comparison must not
     * loosen it into "any URL". Same path, different host.
     */
    $pleb = EinundzwanzigPleb::factory()->create(['statutes_accepted_at' => now()]);
    $pubkey = $pleb->pubkey;

    PaymentEvent::factory()->create([
        'einundzwanzig_pleb_id' => $pleb->id,
        'year' => (int) now()->year,
        'amount' => 21000,
        'paid' => true,
        'btc_pay_invoice' => 'inv-elsewhere',
    ]);

    app(MembershipService::class)->erasePersonalData($pleb);

    $invoice = reconcileInvoice('inv-elsewhere', $pubkey);
    unset($invoice['metadata']['posData']['source']);
    $invoice['metadata']['orderUrl'] = 'https://evil.example'.(parse_url(url()->route('association.profile'), PHP_URL_PATH) ?? '/');

    reconcileFake([$invoice]);

    $this->artisan('membership:reconcile-btcpay')->assertSuccessful();

    Http::assertNotSent(fn (ClientRequest $request): bool => $request->method() === 'PUT');
});
