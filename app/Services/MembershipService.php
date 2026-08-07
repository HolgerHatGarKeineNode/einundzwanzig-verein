<?php

namespace App\Services;

use App\Enums\AssociationStatus;
use App\Enums\MembershipStatus;
use App\Jobs\PublishPaymentEventToNostr;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use App\Models\Profile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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
                'amount' => $this->fee(),
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
     * @return array{payment_event: PaymentEvent, invoice: array<string, mixed>|null, checkout_url: string, created: bool}
     */
    public function createInvoice(EinundzwanzigPleb $pleb, ?int $year = null, string $orderId = ''): array
    {
        $year ??= $this->currentYear();

        $paymentEvent = $this->resolvePaymentEvent($pleb, $year);

        $result = DB::transaction(function () use ($pleb, $paymentEvent, $orderId): array {
            $locked = PaymentEvent::query()
                ->whereKey($paymentEvent->getKey())
                ->lockForUpdate()
                ->first() ?? $paymentEvent;

            if ($locked->btc_pay_invoice) {
                return ['payment_event' => $locked, 'invoice' => null, 'created' => false];
            }

            $invoice = $this->btcPay->createInvoice($this->invoicePayload($pleb, $locked, $orderId));

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
     * Book an incoming payment and let it constitute the membership.
     */
    public function markPaid(PaymentEvent $paymentEvent): PaymentEvent
    {
        DB::transaction(function () use ($paymentEvent): void {
            if (! $paymentEvent->paid) {
                $paymentEvent->update(['paid' => true]);
            }

            $this->grantMembershipOnPayment($paymentEvent);
        });

        return $paymentEvent;
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
        if ($paymentEvent->paid) {
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

        if (in_array($status, self::DEAD_INVOICE_STATUSES, true)) {
            $release = $this->releaseExpiredInvoice($paymentEvent);

            return [
                'payment_event' => $release['payment_event'],
                'status' => $status,
                'released' => $release['released'],
            ];
        }

        if ($status === 'Settled') {
            $paymentEvent = $this->markPaid($paymentEvent);
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
     *    itemDesc). BTCPay is the association's own system, so this IS within
     *    reach in principle — it is a known, deliberately documented residual
     *    gap, not an oversight. Depersonalising it means an outbound call to a
     *    store whose behaviour on metadata updates is unverified from this
     *    repository, and an erasure must not fail or silently half-succeed
     *    because a payment provider is unreachable. The reconciliation path in
     *    P5 is where that belongs, as a retryable job. Until then the exposure
     *    requires access to the BTCPay backend itself: with `btc_pay_invoice`
     *    dropped, nothing in this database leads there any more.
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
     *  2. the consent to the statutes is on record — no consent, no membership,
     *  3. only DEFAULT(1) → PASSIVE(2); ACTIVE(3) and HONORARY(4) are never
     *     touched, otherwise paying the fee would demote an active member,
     *  4. idempotent — a second call changes nothing.
     *
     * Every promotion leaves a MembershipGrant row naming the payment event
     * that caused it.
     */
    public function grantMembershipOnPayment(PaymentEvent $paymentEvent): void
    {
        if (! $paymentEvent->paid) {
            return;
        }

        $pleb = $paymentEvent->pleb;

        if (! $pleb || $pleb->statutes_accepted_at === null) {
            return;
        }

        if ($pleb->association_status !== AssociationStatus::DEFAULT) {
            return;
        }

        DB::transaction(function () use ($pleb, $paymentEvent): void {
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
     * Legacy rows from before the unique index on (pleb, year). A paid record
     * is kept and never deleted, even as a duplicate.
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

        $idsToDelete = $paymentEvents
            ->reject(fn (PaymentEvent $event) => $event->getKey() === $eventToKeep?->getKey() || $event->paid)
            ->map(fn (PaymentEvent $event) => $event->getKey());

        if ($idsToDelete->isNotEmpty()) {
            PaymentEvent::query()->whereIn('id', $idsToDelete)->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoicePayload(EinundzwanzigPleb $pleb, PaymentEvent $paymentEvent, string $orderId): array
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
                ],
            ],
            'checkout' => [
                'expirationMinutes' => 60 * 24,
                'redirectURL' => url()->route('association.profile'),
                'redirectAutomatically' => true,
                'defaultLanguage' => 'de',
            ],
        ];
    }
}
