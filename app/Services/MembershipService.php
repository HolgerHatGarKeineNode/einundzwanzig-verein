<?php

namespace App\Services;

use App\Enums\AssociationStatus;
use App\Jobs\PublishPaymentEventToNostr;
use App\Models\EinundzwanzigPleb;
use App\Models\MembershipGrant;
use App\Models\PaymentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
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

    public function currentYear(): int
    {
        return (int) date('Y');
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

            $locked->btc_pay_invoice = $invoice['id'] ?? null;
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
     * @return array{year: int, fee: int, currency: string, paid: bool, invoice_id: string|null, association_status: AssociationStatus, applied_at: Carbon|null, statutes_accepted_at: Carbon|null, is_member: bool}
     */
    public function status(EinundzwanzigPleb $pleb, ?int $year = null): array
    {
        $year ??= $this->currentYear();

        $paymentEvent = $pleb->paymentEvents()->where('year', $year)->orderByDesc('id')->first();

        return [
            'year' => $year,
            'fee' => $this->fee(),
            'currency' => $this->currency(),
            'paid' => (bool) $paymentEvent?->paid,
            'invoice_id' => $paymentEvent?->btc_pay_invoice,
            'association_status' => $pleb->association_status,
            'applied_at' => $pleb->applied_at,
            'statutes_accepted_at' => $pleb->statutes_accepted_at,
            'is_member' => $pleb->hasPaidMembership($year),
        ];
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
