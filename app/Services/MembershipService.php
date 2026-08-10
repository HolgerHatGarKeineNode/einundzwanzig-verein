<?php

namespace App\Services;

use App\Enums\AssociationStatus;
use App\Enums\MembershipStatus;
use App\Enums\PaymentReviewReason;
use App\Exceptions\MembershipUnavailableException;
use App\Jobs\PublishPaymentEventToNostr;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use App\Models\PaymentReversal;
use App\Models\PaymentReview;
use App\Models\Profile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single home of the membership domain: application, fee year, invoice,
 * status and — exclusively — the promotion to member.
 *
 * All of this used to live inside the Volt component and was unreachable from
 * anywhere else. The public API consumes the very same methods, so there is no
 * second truth about what a membership costs or what makes someone a member.
 */
class MembershipService
{
    /**
     * BTCPay states in which an invoice can no longer be paid, so the fee year
     * has to be freed up for a fresh checkout. The Volt component knows the
     * same two names (`invoiceIsExpired()`); this constant is the one the API
     * reads, and both feed the identical `releaseExpiredInvoice()`.
     *
     * @var list<string>
     */
    public const DEAD_INVOICE_STATUSES = ['Expired', 'Invalid'];

    /**
     * What a BTCPay invoice id may consist of.
     *
     * Checked rather than merely "is a non-empty string", because the value is
     * stored and then pasted into the checkout and receipt URLs a payer is
     * sent to: `{"id":"../../evil"}` produced
     * `https://pay.einundzwanzig.space/i/../../evil` and was handed out with a
     * 200. BTCPay is a trusted upstream and this is not an attack path — it is
     * a foreign value ending up unfiltered in a URL, which is a defect
     * regardless of who supplied it. The class covers what BTCPay actually
     * emits (base58-ish identifiers) and nothing that could carry a path
     * segment or whitespace.
     */
    public const INVOICE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,100}$/D';

    /**
     * Stamped into the posData of every membership invoice, so that a later
     * pass can tell OUR invoices from anything else living in the same BTCPay
     * store.
     *
     * It lives here, next to the code that writes it, and the reconciliation
     * command reads it from here — not the other way round. A domain service
     * that has to import a console command in order to know what it itself
     * produces has the dependency backwards.
     */
    public const INVOICE_SOURCE = 'einundzwanzig-membership';

    /**
     * The one payment method id whose `destination` is a BOLT11.
     *
     * SELECTED BY THIS EXACT VALUE, never by "the first method that has a
     * `destination` key". Measured over the live store (P2,
     * `p2-machbarkeit.md` section (a)): `BTC-LNURL` is present on essentially
     * every invoice and carries `destination` as an EMPTY STRING. Picking the
     * first method with that key therefore hands the client precisely the
     * empty string that looks like a payment request and is none — the case
     * this feature exists to avoid.
     */
    public const LIGHTNING_PAYMENT_METHOD_ID = 'BTC-LN';

    public function __construct(protected BtcPayClient $btcPay) {}

    /**
     * Annual fee, in the configured currency. Never a request value.
     */
    public function fee(): int
    {
        return (int) config('einundzwanzig.config.membership_fee');
    }

    public function currency(): string
    {
        return (string) config('einundzwanzig.config.currency');
    }

    /**
     * Refuse to operate on a fee of zero or less.
     *
     * Measured before this guard existed: an unset or empty `MEMBERSHIP_FEE`
     * casts to 0, and the payload that went to BTCPay carried `amount: 0`.
     * BTCPay treats a zero-amount invoice as settled immediately — and since
     * this phase makes the settled fee constitute the membership, that is a
     * free membership handed out by a missing environment variable. It also
     * poisons the record permanently: a payment event stored with `amount: 0`
     * can never be verified against anything afterwards.
     *
     * Placed at every point where the fee would be SPENT (a new payment event,
     * an outgoing invoice) and nowhere else. Reading an existing record must
     * keep working while somebody fixes the configuration.
     *
     * @throws MembershipUnavailableException
     */
    protected function assertFeeConfigured(): int
    {
        $fee = $this->fee();

        if ($fee <= 0) {
            throw new MembershipUnavailableException(
                'The configured membership fee is not usable: '.$fee
            );
        }

        return $fee;
    }

    /**
     * The fee year in progress.
     *
     * Read from Carbon and not from date(): date() ignores a frozen or
     * travelled test clock, so a year-boundary test would silently keep
     * answering with the real year and pass while proving nothing. The value is
     * identical in production — Carbon::now() is the system clock there — so
     * this only makes the boundary observable, it does not move it.
     */
    public function currentYear(): int
    {
        return (int) now()->year;
    }

    /**
     * Record an application: the data and, above all, the consent to the
     * statutes. It deliberately writes no status — the paid fee constitutes
     * the membership (see grantMembershipOnPayment()).
     *
     * `statutes_accepted_at` is never overwritten: it is the document that
     * carries the membership, and a second application must not backdate or
     * refresh it.
     */
    public function apply(EinundzwanzigPleb $pleb): EinundzwanzigPleb
    {
        $now = now();

        $pleb->update([
            'applied_at' => $now,
            'statutes_accepted_at' => $pleb->statutes_accepted_at ?? $now,
        ]);

        return $pleb;
    }

    /**
     * The one payment event of a member for a given year, creating it if it is
     * missing. The unique index on (einundzwanzig_pleb_id, year) is the
     * authority on races — a losing insert reads the winner instead of
     * bubbling up.
     */
    public function resolvePaymentEvent(EinundzwanzigPleb $pleb, ?int $year = null): PaymentEvent
    {
        $year ??= $this->currentYear();

        $paymentEvents = $this->paymentEventsFor($pleb, $year);

        if ($paymentEvents->count() > 1) {
            $this->pruneDuplicatePaymentEvents($paymentEvents);
            $paymentEvents = $this->paymentEventsFor($pleb, $year);
        }

        if ($paymentEvents->isNotEmpty()) {
            return $paymentEvents->first();
        }

        try {
            $paymentEvent = $pleb->paymentEvents()->create([
                'year' => $year,
                'amount' => $this->assertFeeConfigured(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $pleb->paymentEvents()->where('year', $year)->firstOrFail();
        }

        PublishPaymentEventToNostr::dispatch($paymentEvent->id);

        return $paymentEvent;
    }

    /**
     * Create a BTCPay invoice for the fee year, or return the existing one.
     *
     * The read-check-write runs inside one transaction with `lockForUpdate()`
     * on the payment event. Without it two concurrent calls each created an
     * invoice at BTCPay while only one was ever stored; the other stayed open
     * and unaccounted for, so paying it produced money without a booking.
     *
     * `$returnUrl` is where BTCPay sends the payer after the checkout. It is
     * ALREADY VALIDATED when it arrives here — the allowlist lives in
     * `InvoiceReturnUrl` and is applied by the form request, because an
     * unlisted address has to be refused with a 422 rather than corrected. A
     * null keeps the association's own profile page, which is what every
     * caller before this parameter existed got and still gets.
     *
     * IT ONLY TAKES EFFECT WHEN AN INVOICE IS ACTUALLY CREATED. On the
     * idempotent path there is no payload to put it in: the redirect belongs
     * to the invoice that already exists at BTCPay, and rewriting it would
     * mean a second call to a payment provider to change where a stranger's
     * browser goes afterwards. A client that needs a different return address
     * needs a different invoice.
     *
     * THE BOLT11 IS NOT PART OF THIS ANSWER, on purpose. Reading it costs a
     * second BTCPay round trip (`lightningInvoiceFor()`), and the caller that
     * runs this most often — the association's own profile page — redirects
     * the payer to the checkout immediately and would never look at it. Every
     * caller that wants it asks for it; none pays for it by default.
     *
     * @return array{payment_event: PaymentEvent, invoice: array<string, mixed>|null, checkout_url: string, created: bool}
     */
    public function createInvoice(EinundzwanzigPleb $pleb, ?int $year = null, string $orderId = '', ?string $returnUrl = null): array
    {
        /*
         * BEFORE anything is resolved, created or sent. An existing payment
         * event carrying a sane amount would otherwise sail past the guard in
         * resolvePaymentEvent() and the payload built further down would still
         * ask BTCPay for `amount: 0`.
         */
        $this->assertFeeConfigured();

        $year ??= $this->currentYear();

        $paymentEvent = $this->resolvePaymentEvent($pleb, $year);

        $result = DB::transaction(function () use ($pleb, $paymentEvent, $orderId, $returnUrl): array {
            $locked = PaymentEvent::query()
                ->whereKey($paymentEvent->getKey())
                ->lockForUpdate()
                ->first() ?? $paymentEvent;

            if ($locked->btc_pay_invoice) {
                return ['payment_event' => $locked, 'invoice' => null, 'created' => false];
            }

            $invoice = $this->btcPay->createInvoice($this->invoicePayload($pleb, $locked, $orderId, $returnUrl));

            $invoiceId = $invoice['id'] ?? null;

            /*
             * A 200 without an id is an upstream failure wearing a success's
             * clothes. Taken at face value it stored `null`, reported
             * `created: true` with a checkout URL pointing at nothing, and
             * left the idempotency guard with nothing to hold on to — so the
             * client's retry ordered a SECOND invoice at BTCPay. The guard
             * above is only as strong as what gets written here, which is why
             * this is refused rather than absorbed: the transaction rolls
             * back, the caller is told the truth (503), and no phantom
             * reference is left behind.
             */
            if (! is_string($invoiceId) || preg_match(self::INVOICE_ID_PATTERN, $invoiceId) !== 1) {
                throw new HttpClientException('BTCPay returned no usable invoice id.');
            }

            $locked->btc_pay_invoice = $invoiceId;
            $locked->save();

            return ['payment_event' => $locked, 'invoice' => $invoice, 'created' => true];
        });

        $invoiceId = (string) $result['payment_event']->btc_pay_invoice;

        $result['checkout_url'] = $result['invoice']['checkoutLink']
            ?? $this->btcPay->checkoutUrl($invoiceId);

        return $result;
    }

    /**
     * The BOLT11 of a BTCPay invoice — or null, and never an empty string.
     *
     * BOTH PATHS OF `createInvoice()` END HERE, and the second one is why this
     * takes an invoice id rather than just a payload. On the first call there
     * is a BTCPay payload; on the idempotent repeat there is none — `invoice`
     * is null by design — so a value read only from the payload would be
     * present on the first call and absent on every repeat, which is the one
     * shape a client cannot build on. This reads the payload when there is
     * one and reloads through the stored invoice id when there is not.
     *
     * FAIL-SOFT, DELIBERATELY. Every failure of the extra BTCPay round trip
     * this may need — timeout, 4xx, 5xx, an unparsable body — is answered with
     * null and logged, never rethrown. The reason is not optimism about
     * BTCPay: the BOLT11 is an ADDITIVE convenience, and the checkout URL that
     * travels next to it in the same response is unaffected and still leads to
     * a payable invoice. Letting this call fail the request would turn a
     * missing shortcut into "the association cannot take your money", which is
     * a strictly worse answer to the same outage. `BtcPayClient` throws on
     * purpose so that this decision is made here, once, where it can be read.
     *
     * The catch is `Throwable` rather than `HttpClientException` and that is
     * the same decision, not a wider one by accident: the call is a read whose
     * result is optional, so NOTHING it can raise — including a bug in the
     * parsing below — may reach a caller who is otherwise able to answer.
     *
     * WHAT NULL DOES NOT MEAN: that the invoice expired. Measured (P2,
     * `p2-machbarkeit.md` section (a)): BTCPay keeps handing out the dead
     * BOLT11 of an invoice that expired 785 hours ago, unchanged. Expiry is
     * read from the invoice's `expirationTime`, or from the BOLT11's own
     * timestamp plus its `x` tag — both of which say 1440 minutes here,
     * identical to the invoice's own lifetime, so there is no second deadline
     * to publish. Null means one thing only: no `BTC-LN` method carrying a
     * non-empty destination.
     *
     * @param  array<string, mixed>|null  $invoicePayload  the create response, when this call has one
     */
    public function lightningInvoiceFor(string $invoiceId, ?array $invoicePayload = null): ?string
    {
        /*
         * From the payload first, when it carries the methods. The BTCPay
         * version this installation runs does not put them into the create
         * response — measured, not assumed — so in practice this is the branch
         * that does not fire. It stays because it costs one array read to save
         * a round trip the day a BTCPay version does include them, and because
         * the alternative is a request this method already knows the answer to.
         */
        $fromPayload = $this->extractLightningInvoice($invoicePayload['paymentMethods'] ?? null);

        if ($fromPayload !== null) {
            return $fromPayload;
        }

        if (preg_match(self::INVOICE_ID_PATTERN, $invoiceId) !== 1) {
            return null;
        }

        try {
            $methods = $this->btcPay->invoicePaymentMethods($invoiceId);
        } catch (Throwable $exception) {
            /*
             * The invoice id and the exception CLASS, not the message: a
             * BTCPay error body travels inside `RequestException::getMessage()`
             * and would put upstream response content into this application's
             * log for a call whose failure is already fully described by "it
             * did not answer usably".
             */
            Log::warning('membership.bolt11_unavailable', [
                'invoice' => $invoiceId,
                'exception' => $exception::class,
            ]);

            return null;
        }

        return $this->extractLightningInvoice($methods);
    }

    /**
     * Pick the BOLT11 out of a BTCPay payment-methods list.
     *
     * Two things this refuses to do, both measured against the live store
     * (P2, `p2-machbarkeit.md` section (a)):
     *
     *  1. It selects on `paymentMethodId === 'BTC-LN'` and on nothing else.
     *     `BTC-LNURL` ships on nearly every invoice with `destination` set to
     *     the empty string, so "the first method with a destination" returns
     *     that empty string.
     *  2. It never returns `''`. An empty or whitespace-only destination is
     *     null, because a client checking `if (bolt11)` and a client checking
     *     `if (bolt11 !== null)` must reach the same conclusion.
     *
     * That the Lightning method can be missing altogether is not a
     * precaution: 4 of 239 invoices in the store's history have no `BTC-LN`
     * method, one of them a real membership fee invoice of this very
     * application.
     */
    protected function extractLightningInvoice(mixed $methods): ?string
    {
        if (! is_array($methods)) {
            return null;
        }

        foreach ($methods as $method) {
            if (! is_array($method) || ($method['paymentMethodId'] ?? null) !== self::LIGHTNING_PAYMENT_METHOD_ID) {
                continue;
            }

            $destination = $method['destination'] ?? null;

            if (! is_string($destination) || trim($destination) === '') {
                return null;
            }

            return trim($destination);
        }

        return null;
    }

    /**
     * Derived membership and payment state for one fee year.
     *
     * Accepts null so that a pubkey with no record at all is answerable
     * without inventing one: `GET /membership/me` must work for a first-time
     * caller, and a GET that writes a row is the defect P1 removed. A null
     * subject reports the same thing a fresh record would — DEFAULT, nothing
     * applied for, nothing paid.
     *
     * @return array{year: int, fee: int, currency: string, paid: bool, invoice_id: string|null, association_status: AssociationStatus, membership_status: MembershipStatus, applied_at: Carbon|null, statutes_accepted_at: Carbon|null, is_member: bool}
     */
    public function status(?EinundzwanzigPleb $pleb, ?int $year = null): array
    {
        $year ??= $this->currentYear();

        $paymentEvent = $pleb?->paymentEvents()->where('year', $year)->orderByDesc('id')->first();

        return [
            'year' => $year,
            'fee' => $this->fee(),
            'currency' => $this->currency(),
            'paid' => (bool) $paymentEvent?->paid,
            'invoice_id' => $paymentEvent?->btc_pay_invoice,
            'association_status' => $pleb?->association_status ?? AssociationStatus::DEFAULT,
            'membership_status' => $this->membershipStatus($pleb, $year),
            'applied_at' => $pleb?->applied_at,
            'statutes_accepted_at' => $pleb?->statutes_accepted_at,
            'is_member' => (bool) $pleb?->hasPaidMembership($year),
        ];
    }

    /**
     * The derived membership state — the single source for the API, the Volt
     * UI and every later phase.
     *
     * It lives here and not in a controller or a resource on purpose: the
     * value combines the status enum with the payment history, and the moment
     * two places combine them separately they will eventually disagree about
     * the same person.
     *
     * A pubkey with no record at all is `none`: someone who has never touched
     * the association is in exactly the same position as someone whose record
     * exists but carries no application. That keeps `GET /membership/me`
     * answerable for a first-time caller without writing a row on a GET.
     *
     * Precedence, and why: the membership CATEGORY is asked first. Anyone who
     * ever paid carries a category, and for them the only open question is
     * whether the current year is settled — `member` if it is, `lapsed` if it
     * is not. `lapsed` is the case that makes this method necessary at all:
     * the enum still says ACTIVE while the statutes no longer do.
     *
     * Only for a record without a category does the application matter. Note
     * the one combination the four states do not describe: category DEFAULT
     * with the year already paid. It means the promotion did not happen —
     * `grantMembershipOnPayment()` refuses without recorded consent to the
     * statutes — and `awaiting_payment` is the honest report, because what is
     * still missing is precisely the step that turns the payment into a
     * membership.
     */
    public function membershipStatus(?EinundzwanzigPleb $pleb, ?int $year = null): MembershipStatus
    {
        if (! $pleb) {
            return MembershipStatus::None;
        }

        $year ??= $this->currentYear();

        if ($pleb->association_status->value > AssociationStatus::DEFAULT->value) {
            return $pleb->paymentEvents()->where('year', $year)->where('paid', true)->exists()
                ? MembershipStatus::Member
                : MembershipStatus::Lapsed;
        }

        return $pleb->applied_at !== null
            ? MembershipStatus::AwaitingPayment
            : MembershipStatus::None;
    }

    /**
     * Book an incoming payment and let it constitute the membership — the one
     * gate between money and membership.
     *
     * EVERY path from a payment to a membership runs through here: the BTCPay
     * webhook, `POST /payments/{year}/refresh`, the Volt UI's status poll and
     * the reconciliation command. That is the whole point of putting the
     * checks in this method rather than in the callers. Two controllers each
     * carrying their own copy of "is this the right amount?" is not two
     * safeguards, it is one safeguard and one future gap — the third door
     * somebody adds later arrives unprotected by default. Here it arrives
     * protected by default.
     *
     * WHAT IS VERIFIED, and against what. Not against the webhook body: a
     * BTCPay invoice webhook carries NO amount and NO currency at all. Read
     * off the BTCPay Server source and its own OpenAPI document
     * (BTCPayServer.Client/Models/WebhookInvoiceEvent.cs and
     * BTCPayServer/wwwroot/swagger/v1/swagger.template.webhooks.json, master,
     * fetched 2026-08-07): an InvoiceSettled event has storeId, invoiceId,
     * metadata, manuallyMarked and overPaid on top of the delivery fields, and
     * nothing else. The amount has to be fetched from the store — which is the
     * better source anyway, because it is authenticated and cannot be dictated
     * by whoever is talking to us.
     *
     * The expected amount is what THIS association invoiced
     * (`payment_events.amount`, written from the fee at the time the record
     * was created), not today's configured fee. The general assembly fixes the
     * fee per year (Art. 4); comparing a 2025 fee against the 2026 setting
     * would manufacture a mismatch out of a perfectly correct payment.
     *
     * `$invoice` may be handed in by a caller that has just fetched it anyway,
     * to save a second round trip. Passing nothing is the SAFE default, not
     * the lax one: the method then fetches the invoice itself. There is no way
     * to call this and skip the check.
     *
     * A refusal writes a PaymentReview and returns `settled: false`. It does
     * not throw: money did arrive, and an exception would either lose that
     * fact or turn it into a retry loop at BTCPay. It does not book either.
     *
     * @param  array<string, mixed>|null  $invoice  BTCPay's own representation, if already at hand
     * @param  array{manually_marked?: bool|null, over_paid?: bool|null}  $settlement  What the store said ABOUT the settlement
     * @return array{payment_event: PaymentEvent, settled: bool, review: PaymentReview|null}
     */
    public function markPaid(PaymentEvent $paymentEvent, ?array $invoice = null, string $source = 'unknown', ?string $deliveryId = null, array $settlement = []): array
    {
        $verification = $this->verifyPayment($paymentEvent, $invoice);

        if ($verification['reason'] instanceof PaymentReviewReason) {
            return [
                'payment_event' => $paymentEvent,
                'settled' => false,
                'review' => $this->flagForReview($paymentEvent, $verification, $source, $deliveryId),
            ];
        }

        /*
         * ONE transaction for both writes. Apart they produce "has paid but is
         * not a member" whenever the process dies in between — a state nobody
         * ever goes looking for and nothing ever repairs.
         */
        DB::transaction(function () use ($paymentEvent, $settlement): void {
            if (! $paymentEvent->paid) {
                $paymentEvent->update(['paid' => true]);
            }

            $this->grantMembershipOnPayment($paymentEvent, $settlement);
        });

        return ['payment_event' => $paymentEvent, 'settled' => true, 'review' => null];
    }

    /**
     * Compare what BTCPay says was invoiced against what was billed.
     *
     * Currency is checked before the amount on purpose: "1" against "1" is a
     * match in numbers and a catastrophe in fact if one of them is EUR and the
     * other SATS, and reporting `amount_mismatch` for it would send whoever
     * reads the review row looking in the wrong place.
     *
     * Amounts are compared as floats. BTCPay serialises the amount as a
     * decimal STRING ("21000", "21000.0"), so a string comparison would fail
     * on formatting alone, and the fee is a whole number of satoshis — well
     * inside the range a double represents exactly. What is NOT accepted is a
     * non-numeric or absent value: an amount that cannot be read is not an
     * amount that matches.
     *
     * @param  array<string, mixed>|null  $invoice
     * @return array{reason: PaymentReviewReason|null, expected_amount: int, expected_currency: string, observed_amount: string|null, observed_currency: string|null}
     */
    /**
     * The moment invoices began carrying their own currency — or null when that
     * moment cannot be established.
     *
     * Every unreadable configuration resolves to null, and null means "no
     * waiver, check strictly". That direction is not a style choice: the value
     * is a date in an environment file, and the two ways it goes wrong both
     * point the same way. `Carbon::parse('')` returns NOW rather than throwing,
     * so an emptied variable would move the cut-off to this instant, make every
     * existing row older than it, and lift the currency check for every payment
     * the association will ever receive. And an operator wanting to switch the
     * waiver OFF will reach for exactly that: emptying the value. The most
     * natural wrong move must not be the one that opens the gate.
     */
    protected function explicitCurrencySince(): ?Carbon
    {
        $configured = trim((string) config('einundzwanzig.config.explicit_currency_since'));

        if ($configured === '') {
            return null;
        }

        try {
            return Carbon::parse($configured);
        } catch (Throwable) {
            /*
             * Refusing the waiver, not the request. Throwing here would travel
             * up through the webhook and answer BTCPay with a 500 — it would
             * retry, fail again, and eventually give up on a payment that is
             * perfectly bookable under the strict rule.
             */
            return null;
        }
    }

    /**
     * Was this fee paid against an invoice from before invoices carried their
     * own currency?
     *
     * Such an invoice was sent to BTCPay with an amount and nothing else, so it
     * wears whatever the store happens to default to — a setting that lives
     * outside this repository and that nobody here has read. The association
     * decided on 2026-08-07 not to go looking: for an invoice from that era the
     * currency reported back counts as SATS, and only the amount is verified.
     *
     * TWO AGES HAVE TO BE OLD, AND THE SECOND ONE IS THE POINT. The obvious
     * discriminator — the age of the fee row — is wrong on its own, and
     * measurably so: `resolvePaymentEvent()` creates that row when a member
     * first OPENS THEIR PROFILE PAGE, not when an invoice is created. Every
     * member who looked at their profile before the cut-off and has not paid
     * carries a pre-cut-off row until the year ends, and the waiver would
     * follow it onto an invoice created tomorrow — one that sends its currency
     * explicitly and has no claim to any exception. So BTCPay's own
     * `createdTime` has to be old as well, and a missing or unreadable one
     * refuses the waiver rather than granting it.
     *
     * The row age stays in the condition because it costs nothing and can only
     * narrow: an invoice cannot be older than the row it hangs on.
     *
     * A row with no `created_at` is NOT treated as legacy. Nothing in this
     * application can produce one — `created_at` is not fillable on
     * `PaymentEvent` and no code path forces it — so such a row would come from
     * a hand-written backfill, and the prices are not symmetric: the strict
     * answer costs one line in `payment_reviews` that a human clears in a
     * minute, the lenient one silently disables a check on the money path for
     * that row forever.
     *
     * @param  array<string, mixed>  $invoice  as BTCPay reported it
     */
    protected function predatesExplicitCurrency(PaymentEvent $paymentEvent, array $invoice): bool
    {
        $cutoff = $this->explicitCurrencySince();
        $rowCreatedAt = $paymentEvent->created_at;

        if ($cutoff === null || $rowCreatedAt === null || ! $rowCreatedAt->lessThan($cutoff)) {
            return false;
        }

        $invoiceCreatedTime = $invoice['createdTime'] ?? null;

        if (! is_numeric($invoiceCreatedTime)) {
            return false;
        }

        try {
            $invoiceCreatedAt = Carbon::createFromTimestamp((int) $invoiceCreatedTime);
        } catch (Throwable) {
            /*
             * `is_numeric()` is not the same guarantee as "a moment in time".
             * `9e99`, `1e18` and a twenty-digit integer all pass it and all
             * make `createFromTimestamp()` throw, and the throw would leave
             * this method, `verifyPayment()` and `markPaid()` for a 500 —
             * BTCPay would retry eight times and then leave the payment
             * unbooked forever. Same failure the cut-off parse above is
             * guarded against, one method further along, so the same answer:
             * no proof the invoice is old, therefore no waiver.
             */
            return false;
        }

        return $invoiceCreatedAt->lessThan($cutoff);
    }

    protected function verifyPayment(PaymentEvent $paymentEvent, ?array $invoice): array
    {
        $result = [
            'reason' => null,
            'expected_amount' => (int) $paymentEvent->amount,
            'expected_currency' => $this->currency(),
            'observed_amount' => null,
            'observed_currency' => null,
        ];

        $invoiceId = (string) $paymentEvent->btc_pay_invoice;

        if ($invoiceId === '') {
            $result['reason'] = PaymentReviewReason::MissingInvoiceReference;

            return $result;
        }

        if ($result['expected_amount'] <= 0) {
            $result['reason'] = PaymentReviewReason::UnverifiableAmount;

            return $result;
        }

        $invoice ??= $this->btcPay->getInvoice($invoiceId);

        /*
         * IS THIS THE RIGHT INVOICE, AND DOES IT SAY WHAT WE THINK IT SAYS?
         *
         * Both checks were missing while this method promised there was no way
         * to call it and skip the verification — true of the amount, and empty
         * without these two, because an amount is only evidence about the
         * object it came from. A caller handing in a different invoice, or one
         * BTCPay does not consider settled, was believed.
         *
         * No abuse path exists today: every caller fetches by this row's own
         * invoice id. That is an argument for the checks being cheap, not for
         * leaving them out — the next caller is the one nobody has written yet.
         */
        $observedId = $invoice['id'] ?? null;

        if (is_string($observedId) && $observedId !== '' && ! hash_equals($invoiceId, $observedId)) {
            $result['reason'] = PaymentReviewReason::InvoiceMismatch;

            return $result;
        }

        $observedStatus = $invoice['status'] ?? null;

        if ($observedStatus !== null && $observedStatus !== 'Settled') {
            $result['reason'] = PaymentReviewReason::NotSettled;

            return $result;
        }

        $observedAmount = $invoice['amount'] ?? null;
        $observedCurrency = $invoice['currency'] ?? null;

        $result['observed_amount'] = is_scalar($observedAmount) ? (string) $observedAmount : null;
        $result['observed_currency'] = is_string($observedCurrency) ? $observedCurrency : null;

        if ($result['observed_amount'] === null
            || ! is_numeric($result['observed_amount'])
            || $result['observed_currency'] === null
            || $result['observed_currency'] === '') {
            $result['reason'] = PaymentReviewReason::UnverifiableAmount;

            return $result;
        }

        $currencyWaived = false;

        if (strcasecmp($result['observed_currency'], $result['expected_currency']) !== 0) {
            if (! $this->predatesExplicitCurrency($paymentEvent, $invoice)) {
                $result['reason'] = PaymentReviewReason::CurrencyMismatch;

                return $result;
            }

            $currencyWaived = true;
        }

        if ((float) $result['observed_amount'] !== (float) $result['expected_amount']) {
            $result['reason'] = PaymentReviewReason::AmountMismatch;

            return $result;
        }

        if ($currencyWaived && ! $paymentEvent->paid) {
            /*
             * Tolerated, never silent — but logged only where the word is true.
             *
             * Two placements were wrong before this one. Above the amount
             * comparison, the line claimed an acceptance for payments that were
             * about to be refused for their amount. And without the `paid`
             * guard it fired on every status poll rather than on the booking:
             * `markPaid()` verifies unconditionally, the profile page polls
             * every 20 seconds and `POST /payments/{year}/refresh` allows 30
             * calls a minute, so a single waived fee could produce tens of
             * thousands of identical warnings a day — enough to bury every
             * other warning and to make the count useless for the one question
             * the entry exists to answer: is this exception still needed?
             */
            Log::warning('membership.legacy_currency_accepted', [
                'payment_event_id' => $paymentEvent->id,
                'invoice' => $invoiceId,
                'observed_currency' => $result['observed_currency'],
                'expected_currency' => $result['expected_currency'],
                'row_created_at' => $paymentEvent->created_at?->toIso8601String(),
                'invoice_created_time' => $invoice['createdTime'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * Put a refused payment in front of a human, findable from the database.
     *
     * One open row per (payment event, reason), refreshed rather than
     * repeated. `POST /payments/{year}/refresh` is client-driven and the Volt
     * page polls; without this, a single mismatched invoice would grow a new
     * review row every twenty seconds and bury the queue it exists to fill.
     *
     * @param  array{reason: PaymentReviewReason|null, expected_amount: int, expected_currency: string, observed_amount: string|null, observed_currency: string|null}  $verification
     */
    protected function flagForReview(PaymentEvent $paymentEvent, array $verification, string $source, ?string $deliveryId): PaymentReview
    {
        $review = PaymentReview::query()
            ->where('payment_event_id', $paymentEvent->getKey())
            ->where('reason', $verification['reason']?->value)
            ->whereNull('resolved_at')
            ->first() ?? new PaymentReview;

        $review->fill([
            'payment_event_id' => $paymentEvent->getKey(),
            'einundzwanzig_pleb_id' => $paymentEvent->einundzwanzig_pleb_id,
            'reason' => $verification['reason']?->value,
            'source' => $source,
            'btc_pay_invoice' => $paymentEvent->btc_pay_invoice,
            'delivery_id' => $deliveryId,
            'expected_amount' => $verification['expected_amount'],
            'expected_currency' => $verification['expected_currency'],
            'observed_amount' => $verification['observed_amount'],
            'observed_currency' => $verification['observed_currency'],
        ])->save();

        return $review;
    }

    /**
     * Record a settled invoice that no payment event in this database claims.
     *
     * Money arrived against a claim the association cannot find. Deliberately
     * not silent and deliberately not fatal: the reconciliation command can
     * often work out afterwards who it belonged to, and until then the row is
     * the only trace there is.
     */
    public function flagUnknownInvoice(string $invoiceId, string $source, ?string $deliveryId = null): PaymentReview
    {
        $review = PaymentReview::query()
            ->whereNull('payment_event_id')
            ->where('btc_pay_invoice', $invoiceId)
            ->where('reason', PaymentReviewReason::UnknownInvoice->value)
            ->whereNull('resolved_at')
            ->first() ?? new PaymentReview;

        $review->fill([
            'reason' => PaymentReviewReason::UnknownInvoice->value,
            'source' => $source,
            'btc_pay_invoice' => $invoiceId,
            'delivery_id' => $deliveryId,
            'expected_currency' => $this->currency(),
        ])->save();

        return $review;
    }

    /**
     * Take a booked fee back — the Storno path.
     *
     * WHAT MOVES: `paid`, plus a PaymentReversal that keeps the original entry
     * on the record. WHAT DOES NOT MOVE: the membership category and the
     * MembershipGrant that named this payment. That is decided, not an
     * oversight (plan, "Beitrittsmodell" point 4): a payment provider does not
     * get to end memberships, and Art. 4.2 of the statutes rules out any claim
     * to a refund in the first place. A member who lapses is reported as
     * `lapsed` through `membershipStatus()`, which reads the payment state —
     * so the effect of the reversal is visible without demoting anybody.
     *
     * Idempotent by construction: an unpaid record has nothing to reverse, so
     * a redelivered `InvoiceInvalid` writes no second Storno.
     *
     * @return array{payment_event: PaymentEvent, reversed: bool, reversal: PaymentReversal|null}
     */
    public function reversePayment(PaymentEvent $paymentEvent, string $reason, string $source = 'unknown', ?string $deliveryId = null): array
    {
        if (! $paymentEvent->paid) {
            return ['payment_event' => $paymentEvent, 'reversed' => false, 'reversal' => null];
        }

        $reversal = DB::transaction(function () use ($paymentEvent, $reason, $source, $deliveryId): PaymentReversal {
            $paymentEvent->update(['paid' => false]);

            return PaymentReversal::create([
                'payment_event_id' => $paymentEvent->getKey(),
                'einundzwanzig_pleb_id' => $paymentEvent->einundzwanzig_pleb_id,
                'year' => (int) $paymentEvent->year,
                'amount' => (int) $paymentEvent->amount,
                'currency' => $this->currency(),
                'reason' => $reason,
                'source' => $source,
                'delivery_id' => $deliveryId,
                'reversed_at' => now(),
            ]);
        });

        return ['payment_event' => $paymentEvent, 'reversed' => true, 'reversal' => $reversal];
    }

    /**
     * Drop an expired or invalid invoice so a fresh one can be started.
     *
     * A paid record is never deleted. The old code deleted unconditionally on
     * BTCPay reporting `Expired`/`Invalid`; no attacker was needed — a manual
     * "mark invalid" after a refund was enough to destroy the only proof that
     * the fee had ever been paid.
     *
     * @return array{payment_event: PaymentEvent, released: bool}
     */
    public function releaseExpiredInvoice(PaymentEvent $paymentEvent): array
    {
        /*
         * `hasSettlementHistory()` and NOT `paid`. Measured: a Storno sets
         * `paid` back to false, so a second refresh of the same invalid
         * invoice found an "unpaid" row with a dead checkout, deleted it, and
         * dragged the reversal and the grant along through their cascades. The
         * member kept their category and lost every document explaining it.
         *
         * The flag describes the present; the question here is about the past.
         */
        if ($paymentEvent->hasSettlementHistory()) {
            return ['payment_event' => $paymentEvent, 'released' => false];
        }

        /*
         * ONLY THE CURRENT FEE YEAR MAY BE RELEASED, and this guard sits HERE
         * rather than at a caller because that is the whole thesis of this
         * phase — and I got it wrong the first time.
         *
         * The rule was originally written into the reconciliation command, one
         * of three callers. The other two were unprotected: a member calling
         * `POST /api/v1/membership/payments/2023/refresh` for themselves, and
         * the Volt profile page's own status poll. Measured on the endpoint —
         * BTCPay answers `Expired`, the row has genuinely never been paid so
         * the guard above lets it through, and it is deleted and re-created:
         *
         *   before: amount=15000  event_id='nostr-abc'
         *   after : amount=21000  event_id=NULL
         *
         * `payment_events.amount` is the exact reference `verifyPayment()`
         * measures incoming money against, and the reason it uses the STORED
         * amount rather than the configured one is that the general assembly
         * sets the fee per year (Art. 4). Rewriting a 2023 fee to the 2026
         * figure destroys that reference, and the re-created row queues a
         * fresh kind-32121 publication for a years-old fee on the way out.
         *
         * Nothing is lost by refusing: releasing a year frees it up for a NEW
         * checkout, and there is no new checkout to start for a year that is
         * over — invoice creation only ever serves the current year, and
         * `grantMembershipOnPayment()` refuses any other year anyway. Reading
         * the status of an old invoice keeps working; only the destructive
         * half is off the table.
         */
        if ((int) $paymentEvent->year !== $this->currentYear()) {
            return ['payment_event' => $paymentEvent, 'released' => false];
        }

        $pleb = $paymentEvent->pleb;
        $year = (int) $paymentEvent->year;

        if (! $pleb) {
            return ['payment_event' => $paymentEvent, 'released' => false];
        }

        $paymentEvent->delete();

        return ['payment_event' => $this->resolvePaymentEvent($pleb, $year), 'released' => true];
    }

    /**
     * Ask BTCPay what became of the invoice of one fee year and book the
     * answer — the active counterpart to the webhook.
     *
     * It exists because the webhook is the joining path and it comes from
     * outside: a lost delivery leaves somebody who has paid without a
     * membership, and nobody would notice. This call reproduces the very same
     * effects through the very same methods (`markPaid()`, and with it
     * `grantMembershipOnPayment()`), so a refresh can never grant a membership
     * on terms the webhook would have refused.
     *
     * The upstream failure is deliberately NOT caught here. Whether an
     * unreachable payment provider is a 503, a retry or a queued job is a
     * transport decision, and the domain has no business making it; the caller
     * decides. What the domain does guarantee is that nothing is written on
     * the way to the failure — `getInvoice()` is the first statement that can
     * throw, and every write below it depends on its result.
     *
     * @return array{payment_event: PaymentEvent, status: string|null, released: bool}
     */
    public function refreshPaymentStatus(PaymentEvent $paymentEvent): array
    {
        $invoiceId = (string) $paymentEvent->btc_pay_invoice;

        if ($invoiceId === '') {
            return ['payment_event' => $paymentEvent, 'status' => null, 'released' => false];
        }

        $invoice = $this->btcPay->getInvoice($invoiceId);

        $status = $invoice['status'] ?? null;
        $status = is_string($status) ? $status : null;

        /*
         * A BOOKED fee reported as invalid is a Storno, and it is checked
         * before the dead-invoice branch because that branch would swallow it:
         * `Invalid` is one of the two dead statuses, and releaseExpiredInvoice()
         * declines on a paid record and returns — leaving `paid` standing on a
         * fee BTCPay has just disowned. Freeing the year is the right answer
         * for an UNPAID checkout; taking the booking back with a record is the
         * right answer for a paid one. Same status, two different facts.
         */
        if ($status === 'Invalid' && $paymentEvent->paid) {
            $reversal = $this->reversePayment($paymentEvent, 'InvoiceInvalid', source: 'refresh');

            return [
                'payment_event' => $reversal['payment_event']->refresh(),
                'status' => $status,
                'released' => false,
            ];
        }

        if (in_array($status, self::DEAD_INVOICE_STATUSES, true)) {
            $release = $this->releaseExpiredInvoice($paymentEvent);

            return [
                'payment_event' => $release['payment_event'],
                'status' => $status,
                'released' => $release['released'],
            ];
        }

        if ($status === 'Settled') {
            /*
             * The invoice just fetched is handed straight to markPaid(): it is
             * BTCPay's own answer, carrying the amount and currency, so the
             * verification runs on it without a second round trip. Passing
             * nothing would be equally safe and merely slower.
             */
            $paymentEvent = $this->markPaid($paymentEvent, $invoice, source: 'refresh')['payment_event'];
        }

        return ['payment_event' => $paymentEvent->refresh(), 'status' => $status, 'released' => false];
    }

    /**
     * Erase the person from a member record while keeping the booking.
     *
     * A `delete()` is not available here and the reason is not squeamishness:
     * `payment_events` and `membership_grants` both hang off this row with
     * `cascadeOnDelete`, so deleting it would destroy the association's proof
     * of which annual fees it received — a record it must be able to produce,
     * and one the statutes back up by ruling out any claim to a refund
     * (Art. 4.2). Erasure under revDSG (and Art. 17(3) GDPR for members in the
     * EU) is therefore the removal of the PERSONAL REFERENCE, not of the
     * bookkeeping entry.
     *
     * What is cleared: e-mail address, NIP-05 handle, both application texts
     * and `application_for` — an erased person has no pending request to the
     * board. The blind index of the address goes with them, dropped from
     * CipherSweet's own table, because a searchable hash of an e-mail address
     * is a personal reference like any other.
     *
     * What is replaced: `pubkey` and `npub`. They are the identity itself, so
     * they cannot merely be emptied — the columns are not nullable and the
     * pubkey is uniquely indexed. Each is overwritten with a RANDOM tombstone
     * carrying a `deleted-` prefix, which by construction can never match
     * `^[0-9a-f]{64}$` and therefore can never be reached by a signature
     * again. Random rather than a hash of the old pubkey on purpose: a hash
     * would be recomputable from any candidate key, so anybody holding a list
     * of npubs could re-establish the link that this call exists to sever.
     *
     * What is kept, and why each: the annual fees with their years, amounts
     * and settled flag — but NOT their `event_id` and `btc_pay_invoice`, which
     * point at records elsewhere that still name the person (see below) — the
     * `membership_grants` rows (they answer "which payment constituted this
     * membership?" and hold no personal data), and
     * `association_status`, `applied_at` and `statutes_accepted_at` — the
     * consent timestamp is the document the membership rests on, and lowering
     * a category is a board decision, never a side effect of a data-protection
     * request. Note that the anonymised row can no longer exercise any right
     * either way: no signature reaches it any more.
     *
     * `no_email` is set because it now describes the record truthfully: there
     * is no contact channel, and an unset flag would read as "wants e-mail but
     * none is stored".
     *
     * The cached Nostr profile (kind 0) is deleted outright. It is a copy of
     * data the person published themselves, held here only as a lookup
     * convenience, and nothing in the bookkeeping depends on it.
     *
     * WHAT THIS METHOD DOES NOT REACH, stated because a guarantee believed to
     * be complete is worse than one known to be partial:
     *
     *  - The kind-32121 events already published to Nostr relays. They are
     *    public, signed and outside anybody's control — a relay cannot be made
     *    to forget. Dropping `event_id` removes the association's own pointer
     *    to them, which is all this side can do; the events remain findable by
     *    anyone who already knows the pubkey. That is a property of publishing
     *    to Nostr at all, not of this erasure.
     *  - The BTCPay invoice metadata (`posData.pubkey`, `posData.npub`,
     *    itemDesc). CLOSED IN P5, but NOT here and not synchronously:
     *    `membership:reconcile-btcpay`
     *    (App\Console\Commands\ReconcileBtcPayInvoices) lists the store's
     *    invoices and strips every one whose pubkey no longer belongs to a
     *    living member record. It has to work that way round because THIS
     *    method destroys the only route from here to there — `btc_pay_invoice`
     *    is cleared below — so the reconciliation comes in from the BTCPay
     *    side. Deliberately not done inline: an outbound call to a payment
     *    provider must never be able to make an erasure fail or half-succeed,
     *    and a failed scrub there is a logged line plus a return trip on the
     *    next run. Between the erasure and the next run the exposure requires
     *    access to the BTCPay backend itself.
     *  - The PRIMARY KEY survives, and it is the same one the public
     *    `GET /api/members/{year}` publishes. Filtering erased rows out of that
     *    list (`scopeNotErased`) takes the tombstone out of the PRESENT — it
     *    does not undo the past: a single historical snapshot of that list,
     *    held by anyone, plus later access to the database (a backup, an
     *    insider, a subject access request) binds every anonymised row back to
     *    its npub through the id alone. The DISAPPEARANCE is a signal too:
     *    a list for a FIXED year only loses a row through an erasure. Removing
     *    `id` from that field list would close it and would be a breaking
     *    change for external consumers — a user's decision, deliberately not
     *    taken here.
     *  - `payment_events.created_at` survives and sits seconds away from the
     *    timestamp of the public kind-32121 event for the same fee. Same class
     *    as `event_id`, over time instead of over an identifier. How
     *    discriminating that is depends on how many fees are booked within the
     *    same seconds and is UNMEASURED — recorded as a known unknown, not as
     *    an assessed low risk.
     *
     * @return array{retained_payments: int}
     */
    public function erasePersonalData(EinundzwanzigPleb $pleb): array
    {
        return DB::transaction(function () use ($pleb): array {
            Profile::query()->where('pubkey', $pleb->pubkey)->delete();

            $pleb->pubkey = self::tombstone(64);
            $pleb->npub = self::tombstone(62);
            $pleb->email = null;
            $pleb->nip05_handle = null;
            $pleb->no_email = true;
            $pleb->application_text = null;
            $pleb->archived_application_text = null;
            $pleb->application_for = null;
            $pleb->save();

            /*
             * THE BOOKING STAYS, ITS POINTERS OUT OF THE HOUSE DO NOT.
             *
             * What has to survive an erasure is the entry — year, amount,
             * settled — because the association must be able to account for
             * the fees it received. Neither of these two columns is part of
             * that. Both are references to records that still name the person
             * in the clear, and keeping them turned the anonymised row back
             * into a pointer at the identity it was supposed to lose:
             *
             *  - `event_id` addresses a PUBLIC kind-32121 event whose `d` tag
             *    is literally "<pubkey>,<year>" (PublishPaymentEventToNostr).
             *    Anyone holding the id can read the pubkey off a relay.
             *  - `btc_pay_invoice` addresses an invoice whose metadata carries
             *    `posData.pubkey`, `posData.npub` and an itemDesc naming the
             *    npub. BTCPay is the association's own system, so that copy is
             *    squarely within the reach of an erasure request.
             *
             * Written straight through the query builder on purpose: this must
             * touch every fee row of this member without loading, mutating and
             * saving each model, and there is nothing here for a model event
             * to do.
             */
            $pleb->paymentEvents()->update([
                'event_id' => null,
                'btc_pay_invoice' => null,
            ]);

            /*
             * After the save, never before: saving re-upserts the blind index
             * from the current attributes, so an earlier delete would simply
             * be written back. The row is dropped rather than left holding the
             * index of an empty address — a searchable hash of a person's
             * e-mail is exactly the personal reference this method removes,
             * and CipherSweet keeps it in a table of its own where nothing
             * else would ever notice it survived.
             */
            $pleb->deleteBlindIndexes();

            return ['retained_payments' => $pleb->paymentEvents()->count()];
        });
    }

    /**
     * An identifier that is unique, fits the column and can never be a pubkey.
     *
     * The `deleted-` prefix is what does the work: it keeps the value outside
     * the lowercase-hex alphabet, so no NIP-98 signature can ever address this
     * row again — not even by accident, and not by a caller who happened to
     * see the value.
     */
    protected static function tombstone(int $length): string
    {
        return substr('deleted-'.bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    /**
     * The ONLY place in the code base that raises `association_status`.
     *
     * Conditions, each individually checkable and in this order:
     *  1. the payment is settled,
     *  2. it is settled for the CURRENT fee year,
     *  3. the consent to the statutes is on record — no consent, no membership,
     *  4. only DEFAULT(1) → PASSIVE(2); ACTIVE(3) and HONORARY(4) are never
     *     touched, otherwise paying the fee would demote an active member,
     *  5. idempotent — a second call changes nothing.
     *
     * Condition 2 belongs HERE and not in the invoice endpoint, where the year
     * limit used to live alone. Measured before it moved: a settled payment
     * event for the year 1970 over 1 satoshi raised a record to PASSIVE and
     * wrote a grant dated 1970. `POST /payments/{year}/refresh` accepts any
     * year on purpose — an unsettled invoice from a previous year is still
     * worth resolving — and it runs through this very method, so the guard on
     * the other endpoint protected nothing. A membership is the right to take
     * part in THIS year's association; a fee for a year long past does not
     * confer it.
     *
     * Every promotion leaves a MembershipGrant row naming the payment event
     * that caused it.
     *
     * @param  array{manually_marked?: bool|null, over_paid?: bool|null}  $settlement
     */
    public function grantMembershipOnPayment(PaymentEvent $paymentEvent, array $settlement = []): void
    {
        if (! $paymentEvent->paid) {
            return;
        }

        if ((int) $paymentEvent->year !== $this->currentYear()) {
            return;
        }

        $pleb = $paymentEvent->pleb;

        if (! $pleb || $pleb->statutes_accepted_at === null) {
            return;
        }

        if ($pleb->association_status !== AssociationStatus::DEFAULT) {
            return;
        }

        DB::transaction(function () use ($pleb, $paymentEvent, $settlement): void {
            $locked = EinundzwanzigPleb::query()
                ->whereKey($pleb->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->association_status !== AssociationStatus::DEFAULT) {
                return;
            }

            $locked->association_status = AssociationStatus::PASSIVE;
            $locked->save();

            MembershipGrant::create([
                'einundzwanzig_pleb_id' => $locked->getKey(),
                'payment_event_id' => $paymentEvent->getKey(),
                'from_status' => AssociationStatus::DEFAULT,
                'to_status' => AssociationStatus::PASSIVE,
                'year' => (int) $paymentEvent->year,
                /*
                 * Whether a person clicked "mark settled" in the BTCPay
                 * backend rather than a payment being observed. Null where the
                 * caller could not know — a refresh reads the invoice, which
                 * does not carry the flag. The grant is the answer to "why is
                 * this person a member", and that answer is materially
                 * different when no money was ever seen.
                 */
                'manually_marked' => $settlement['manually_marked'] ?? null,
                'over_paid' => $settlement['over_paid'] ?? null,
                'granted_at' => now(),
            ]);
        });

        $pleb->refresh();
    }

    /**
     * @return Collection<int, PaymentEvent>
     */
    protected function paymentEventsFor(EinundzwanzigPleb $pleb, int $year): Collection
    {
        return $pleb->paymentEvents()->where('year', $year)->orderByDesc('id')->get();
    }

    /**
     * Legacy rows from before the unique index on (pleb, year). A record that
     * money was ever booked against is kept and never deleted, even as a
     * duplicate.
     *
     * @param  Collection<int, PaymentEvent>  $paymentEvents
     */
    protected function pruneDuplicatePaymentEvents(Collection $paymentEvents): void
    {
        $eventToKeep = $paymentEvents
            ->sortByDesc(fn (PaymentEvent $event) => [
                (int) $event->paid,
                $event->updated_at?->timestamp ?? 0,
            ])
            ->first();

        /*
         * Same correction as in releaseExpiredInvoice(), for the same reason:
         * a reversed fee reads as unpaid, and pruning it would destroy the
         * Storno and the grant that hang off it. Nothing with a settlement
         * history is a duplicate worth removing.
         */
        $idsToDelete = $paymentEvents
            ->reject(fn (PaymentEvent $event) => $event->getKey() === $eventToKeep?->getKey() || $event->hasSettlementHistory())
            ->map(fn (PaymentEvent $event) => $event->getKey());

        if ($idsToDelete->isNotEmpty()) {
            PaymentEvent::query()->whereIn('id', $idsToDelete)->delete();
        }
    }

    /**
     * @param  string|null  $returnUrl  an ALREADY VALIDATED return address, or null for the association's own profile page
     * @return array<string, mixed>
     */
    protected function invoicePayload(EinundzwanzigPleb $pleb, PaymentEvent $paymentEvent, string $orderId, ?string $returnUrl = null): array
    {
        return [
            'amount' => $this->fee(),
            /*
             * Sent explicitly. Without it BTCPay applied the store's default
             * currency, so a change over there silently rescaled the fee by
             * orders of magnitude.
             */
            'currency' => $this->currency(),
            'metadata' => [
                'orderId' => $orderId,
                'orderUrl' => url()->route('association.profile'),
                'itemDesc' => 'Mitgliedsbeitrag '.$paymentEvent->year.' von nostr:'.$pleb->npub,
                'posData' => [
                    /*
                     * May be null while the kind-32121 publication is still
                     * queued. Nothing depends on it: the webhook matches on
                     * btc_pay_invoice.
                     */
                    'event' => $paymentEvent->event_id,
                    'pubkey' => $pleb->pubkey,
                    'npub' => $pleb->npub,
                    /*
                     * For the reconciliation command. An invoice that never
                     * made it into this database has to say which fee year it
                     * belongs to on its own, and the only alternative was
                     * reading the year back out of the German sentence in
                     * `itemDesc`. Carries no personal reference, so unlike the
                     * two lines above it survives a depersonalisation.
                     */
                    'year' => (int) $paymentEvent->year,
                    /*
                     * WHOSE INVOICE THIS IS. A BTCPay store can serve more
                     * than one integration, and the reconciliation command
                     * rewrites metadata — so it needs a marker that only this
                     * method produces. Deducing ownership from the shape of
                     * posData was tried and measurably wrong: a foreign
                     * invoice carrying any 64-hex value was treated as ours
                     * and had its booking data destroyed.
                     *
                     * Names a system, not a person, so it survives a
                     * depersonalisation — which is exactly what a later run
                     * needs in order to recognise an already-scrubbed invoice
                     * as still ours.
                     */
                    'source' => self::INVOICE_SOURCE,
                ],
            ],
            'checkout' => [
                'expirationMinutes' => 60 * 24,
                /*
                 * WHERE THE PAYER LANDS AFTERWARDS. Null — the case of every
                 * caller that existed before this was configurable, including
                 * the association's own profile page — keeps the profile
                 * route, byte for byte what was sent before.
                 *
                 * A non-null value has already passed the allowlist in
                 * `InvoiceReturnUrl`; nothing is filtered here. This is
                 * written down because the value reaches a third party that
                 * will send a browser to it: were the check ever moved or
                 * dropped upstream, this line would forward an open redirect
                 * without a single local symptom.
                 */
                'redirectURL' => $returnUrl ?? url()->route('association.profile'),
                'redirectAutomatically' => true,
                'defaultLanguage' => 'de',
            ],
        ];
    }
}
