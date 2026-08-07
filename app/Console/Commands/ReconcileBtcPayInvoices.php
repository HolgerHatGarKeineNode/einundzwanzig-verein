<?php

namespace App\Console\Commands;

use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use App\Services\BtcPayClient;
use App\Services\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Walk the gap between what BTCPay knows and what this database knows.
 *
 * Two holes are closed here, and they are holes precisely because nothing in
 * this database points at them:
 *
 *  1. ORPHANED INVOICES. Invoice creation is a write here and a write there.
 *     If BTCPay answers with something unusable, the transaction rolls back and
 *     the invoice at BTCPay survives without a counterpart — and if somebody
 *     pays it, money arrives against no claim. The webhook files those as
 *     `unknown_invoice`; this command is what looks for them without waiting
 *     for a delivery, and adopts the ones it can match.
 *
 *  2. DEPERSONALISING ERASED MEMBERS' INVOICES. `erasePersonalData()` clears
 *     `payment_events.btc_pay_invoice`, so afterwards there is provably NO
 *     route from this database to the invoice that still carries the person's
 *     `posData.pubkey`, `posData.npub` and a naming `itemDesc`. That residual
 *     gap is documented in that method; this is where it gets closed. The only
 *     way in is from the BTCPay side: list the invoices, and scrub every one
 *     whose pubkey no longer belongs to a living member record.
 *
 * WHY THE ERASURE ITSELF DOES NOT DO THIS: an outbound call to a payment
 * provider must never be able to make a data-protection request fail, and
 * BTCPay's behaviour on metadata updates is unverified from this repository.
 * Here a failure is a logged line and a return trip on the next run.
 *
 * THE ONE BOUND THAT REMAINS, named rather than left to be discovered: the run
 * only looks back `--days` (400 by default). Pagination means nothing inside
 * that window is missed, but an invoice OLDER than it is never revisited — so
 * an erasure request for a member whose last fee predates the window needs a
 * deeper sweep (`--days=3650`) to be complete. Routine runs stay cheap; the
 * deep sweep is the deliberate, occasional one.
 *
 * THE RUN NEVER THROWS, AND IT NEVER LIES. Every invoice is handled on its own,
 * so one bad row does not abort the rest, and nothing is ever left half
 * written: the state that makes a retry work is not kept here at all but
 * re-derived from BTCPay on every run, so "repeatable" cannot itself go stale.
 *
 * But a run that could not see the whole store REPORTS FAILURE (exit 1) rather
 * than shrugging. An earlier draft exited 0 whatever happened, reasoning that
 * nothing was lost — true, and beside the point. "Nothing was lost" is not the
 * same claim as "the work was done", the exit code is the only part of this a
 * monitor can read, and a provider that has been unreachable for a week would
 * have been reported as healthy every single time.
 */
class ReconcileBtcPayInvoices extends Command
{
    protected $signature = 'membership:reconcile-btcpay
                            {--days=400 : How far back to ask BTCPay for invoices}
                            {--page-size=100 : Invoices per request to BTCPay}
                            {--max-pages=50 : Safety stop, so a huge store cannot spin forever}
                            {--dry-run : Report what would change, write nothing}';

    protected $description = 'Reconcile payment events against BTCPay: settle pending invoices, adopt orphans, depersonalise erased members.';

    /**
     * Metadata keys that name a person and have no business surviving an
     * erasure. `orderId`, `orderUrl` and the posData `event` are left alone —
     * none of them identifies anybody once `event_id` is gone on this side.
     *
     * @var list<string>
     */
    private const PERSONAL_POS_DATA_KEYS = ['pubkey', 'npub'];

    public function handle(MembershipService $membership, BtcPayClient $btcPay): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->settlePendingInvoices($membership);

        $startDate = now()->subDays((int) $this->option('days'))->timestamp;
        $pageSize = max(1, (int) $this->option('page-size'));
        $maxPages = max(1, (int) $this->option('max-pages'));

        $seen = 0;
        $adopted = 0;
        $flagged = 0;
        $scrubbed = 0;
        $reachedEnd = false;

        /*
         * PAGINATED, and that is not tidiness. A single capped request would
         * make the depersonalisation guarantee quietly false: an erased
         * member whose invoice happened to fall outside the first page would
         * keep their pubkey and npub at BTCPay forever, and every run would
         * report success. A promise of erasure that silently covers only the
         * most recent N invoices is worse than no promise.
         */
        for ($page = 0; $page < $maxPages; $page++) {
            try {
                $invoices = $btcPay->listInvoices([
                    'startDate' => $startDate,
                    'skip' => $page * $pageSize,
                    'take' => $pageSize,
                ]);
            } catch (Throwable $e) {
                /*
                 * Whatever earlier pages achieved stands on its own — every
                 * action here is independent and idempotent — and the next run
                 * starts over from the first page. What does NOT stand is the
                 * verdict: `$reachedEnd` is never set, so the command exits
                 * non-zero and a monitor sees it.
                 */
                Log::warning('BTCPay reconciliation could not list invoices; nothing lost, will retry.', [
                    'page' => $page,
                    'exception' => $e->getMessage(),
                ]);
                $this->warn('BTCPay is unreachable. Reconciliation stopped early; run again later.');

                break;
            }

            foreach ($invoices as $invoice) {
                $seen++;
                $invoiceId = $invoice['id'] ?? null;

                if (! is_string($invoiceId) || $invoiceId === '') {
                    continue;
                }

                /*
                 * Everything below this line writes — into this database or,
                 * worse, into somebody else's invoice at BTCPay. Nothing that
                 * is not demonstrably one of our own membership fees gets
                 * past here. See isMembershipInvoice() for what "demonstrably"
                 * means and for the foreign invoice that was measurably
                 * damaged before it did.
                 */
                if (! $this->isMembershipInvoice($invoice)) {
                    continue;
                }

                try {
                    if ($this->adoptOrphan($membership, $invoice, $invoiceId, $dryRun)) {
                        $adopted++;
                    } elseif ($this->flagOrphan($membership, $invoice, $invoiceId, $dryRun)) {
                        $flagged++;
                    }

                    if ($this->depersonalise($btcPay, $invoice, $invoiceId, $dryRun)) {
                        $scrubbed++;
                    }
                } catch (Throwable $e) {
                    /*
                     * One invoice, one failure. The loop carries on and the
                     * next run picks this one up again — the alternative,
                     * aborting, would let a single unreadable record block
                     * every other member's reconciliation indefinitely.
                     */
                    Log::warning('BTCPay reconciliation skipped an invoice.', [
                        'invoice' => $invoiceId,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            if (count($invoices) < $pageSize) {
                $reachedEnd = true;

                break;
            }
        }

        $this->info(sprintf(
            'Reconciled %d invoice(s): %d adopted, %d flagged for review, %d depersonalised.%s',
            $seen, $adopted, $flagged, $scrubbed, $dryRun ? ' (dry run)' : ''
        ));

        /*
         * A RUN THAT COULD NOT SEE THE WHOLE PICTURE REPORTS FAILURE.
         *
         * The first draft of this command exited 0 whatever happened, on the
         * argument that nothing was lost and the next run would catch up. Both
         * halves of that are still true — no exception escapes, no state is
         * half-written, every action is re-derived from BTCPay — but "nothing
         * was lost" is not the same claim as "the work was done", and only the
         * exit code is machine-readable. A monitor watching it would have
         * reported a payment provider that had been unreachable for a week as
         * healthy. "Watch the log line, not the status" is advice, not a
         * guarantee.
         */
        return $reachedEnd ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Ask BTCPay about every fee that is on record as unpaid but has a
     * checkout — the webhook's safety net.
     *
     * It runs through `refreshPaymentStatus()`, so a settlement found here is
     * verified and booked on exactly the terms the webhook would have applied.
     * No promotion rule is repeated in this file.
     */
    private function settlePendingInvoices(MembershipService $membership): void
    {
        /*
         * NO YEAR LIMIT HERE — deliberately, and this is a correction.
         *
         * The limit lived at this spot for one round, which made it a rule
         * belonging to ONE of three callers of `releaseExpiredInvoice()`; the
         * refresh endpoint and the Volt page still destroyed historical fees.
         * It now sits in `releaseExpiredInvoice()` itself, where all three
         * inherit it, and repeating it here would be a second copy of a rule
         * that has exactly one home.
         *
         * Removing it also gives this sweep back something worth doing: an old
         * fee that WAS settled but never booked — a webhook delivery lost for
         * good — is picked up and booked. It confers no membership (the year
         * check in `grantMembershipOnPayment()` sees to that) but the books
         * should still say the money arrived.
         */
        $pending = PaymentEvent::query()
            ->whereNotNull('btc_pay_invoice')
            ->where('paid', false)
            ->get();

        foreach ($pending as $paymentEvent) {
            try {
                $membership->refreshPaymentStatus($paymentEvent);
            } catch (Throwable $e) {
                Log::warning('BTCPay reconciliation could not refresh a payment event.', [
                    'payment_event' => $paymentEvent->getKey(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * An invoice BTCPay knows and this database does not — try to give it back
     * its payment event.
     *
     * Adoption only happens when the fee year has no checkout of its own. If
     * it already points at a DIFFERENT invoice, overwriting would simply move
     * the orphan from one id to the other; that case is handed to a human
     * instead.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function adoptOrphan(MembershipService $membership, array $invoice, string $invoiceId, bool $dryRun): bool
    {
        if ($this->paymentEventFor($invoiceId) !== null) {
            return false;
        }

        $pubkey = $this->posData($invoice)['pubkey'] ?? null;

        if (! is_string($pubkey) || preg_match('/^[0-9a-f]{64}$/D', $pubkey) !== 1) {
            return false;
        }

        $pleb = EinundzwanzigPleb::query()->notErased()->where('pubkey', $pubkey)->first();

        if (! $pleb) {
            return false;
        }

        $year = $this->invoiceYear($invoice);

        if ($year === null) {
            return false;
        }

        $paymentEvent = $pleb->paymentEvents()->where('year', $year)->orderByDesc('id')->first();

        if (! $paymentEvent || $paymentEvent->btc_pay_invoice) {
            return false;
        }

        if ($dryRun) {
            $this->line("would adopt invoice {$invoiceId} for pleb {$pleb->getKey()} ({$year})");

            return true;
        }

        $paymentEvent->btc_pay_invoice = $invoiceId;
        $paymentEvent->save();

        if (($invoice['status'] ?? null) === 'Settled') {
            /*
             * Booked through the same gate as everything else, with the
             * invoice already in hand — so an adopted orphan for the wrong
             * amount ends up in the review queue rather than in a membership.
             */
            $membership->markPaid($paymentEvent, $invoice, source: 'reconcile');
        }

        return true;
    }

    /**
     * A settled invoice that could not be matched to anybody. Money arrived
     * and the association cannot say against what — the one case that always
     * needs a person.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function flagOrphan(MembershipService $membership, array $invoice, string $invoiceId, bool $dryRun): bool
    {
        if (($invoice['status'] ?? null) !== 'Settled') {
            return false;
        }

        if ($this->paymentEventFor($invoiceId) !== null) {
            return false;
        }

        if ($dryRun) {
            $this->line("would flag orphaned settled invoice {$invoiceId}");

            return true;
        }

        $membership->flagUnknownInvoice($invoiceId, 'reconcile');

        return true;
    }

    /**
     * Strip the person out of an invoice belonging to a member who no longer
     * exists here.
     *
     * OWNERSHIP IS NOT DECIDED HERE. `isMembershipInvoice()` has already
     * refused anything that is not demonstrably one of our own fees, and that
     * separation is the correction of a measured defect: this method used to
     * treat "posData.pubkey is 64 hex" AS the ownership test, which it never
     * was — anything can put a hex string there, a store can serve several
     * integrations, and a foreign invoice had its booking data destroyed.
     *
     * What is decided here is only whether the person is gone. A living member
     * record answering to that pubkey means leave it alone; nothing answering
     * means the record was erased, because an erasure replaces the pubkey with
     * a tombstone that by construction is not hex and can never match again.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function depersonalise(BtcPayClient $btcPay, array $invoice, string $invoiceId, bool $dryRun): bool
    {
        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $posData = $this->posData($invoice);
        $pubkey = $posData['pubkey'] ?? null;

        if (! is_string($pubkey) || preg_match('/^[0-9a-f]{64}$/D', $pubkey) !== 1) {
            return false;
        }

        if (EinundzwanzigPleb::query()->notErased()->where('pubkey', $pubkey)->exists()) {
            return false;
        }

        if ($dryRun) {
            $this->line("would depersonalise invoice {$invoiceId}");

            return true;
        }

        foreach (self::PERSONAL_POS_DATA_KEYS as $key) {
            unset($posData[$key]);
        }

        $metadata['posData'] = $posData;

        /*
         * The item description is rewritten rather than dropped: BTCPay shows
         * it in the store's own invoice list, and an empty line there would
         * make a reconciled record look damaged instead of anonymised. The
         * replacement names the fee and nothing else.
         */
        $metadata['itemDesc'] = 'Mitgliedsbeitrag (Mitglied geloescht)';

        $btcPay->updateInvoiceMetadata($invoiceId, $metadata);

        return true;
    }

    private function paymentEventFor(string $invoiceId): ?PaymentEvent
    {
        return PaymentEvent::query()->where('btc_pay_invoice', $invoiceId)->first();
    }

    /**
     * Is this invoice a membership fee raised by THIS application?
     *
     * The previous test — "posData.pubkey looks like 64 hex, and no living
     * member answers to it" — was not a test of ownership at all. Anything can
     * put a hex string in posData, and a BTCPay store may serve more than one
     * integration. Measured with a foreign invoice carrying
     * `orderId: meetup-ticket-77` and an unrelated pubkey: the run rewrote its
     * booking data and replaced its description with "Mitgliedsbeitrag
     * (Mitglied geloescht)". Destroying a stranger's records while claiming to
     * protect a member's privacy is the same class of mistake as the leak.
     *
     * Two accepted markers, both produced only by `invoicePayload()`:
     *
     *  - `posData.source`, stamped on every invoice from this phase onwards.
     *    Unambiguous, and it survives depersonalisation because it names a
     *    system rather than a person.
     *  - `metadata.orderUrl` pointing at this application's own profile route,
     *    for invoices created before the marker existed. Narrow enough:
     *    another integration in the same store would have to be pointing its
     *    customers at our members' page.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function isMembershipInvoice(array $invoice): bool
    {
        if (($this->posData($invoice)['source'] ?? null) === MembershipService::INVOICE_SOURCE) {
            return true;
        }

        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        $orderUrl = $metadata['orderUrl'] ?? null;

        return is_string($orderUrl)
            && $this->urlIdentity($orderUrl) !== null
            && $this->urlIdentity($orderUrl) === $this->urlIdentity(url()->route('association.profile'));
    }

    /**
     * Host and path of a URL, normalised — the parts that actually say "this
     * is our members' page".
     *
     * A byte comparison against `url()->route(...)` was tried and is too
     * brittle to carry an erasure promise. The stored `orderUrl` was written
     * by whatever `APP_URL` was configured on the day the invoice was created,
     * and the plan already records `APP_URL` as a fragile operating parameter
     * in its own right. Measured against one and the same old invoice, only
     * the orderUrl varying: a `www.` prefix, a different scheme, or a trailing
     * slash each made the run report "0 depersonalised" and exit 0 — a silent
     * hole in the erasure, which is the one kind of incompleteness that must
     * never be silent.
     *
     * Scheme is dropped (http/https say nothing about ownership), `www.` is
     * dropped, the trailing slash is dropped, the host is lowercased. Host and
     * path still have to match, so a foreign URL is still foreign.
     */
    private function urlIdentity(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return null;
        }

        $host = (string) preg_replace('/^www\./', '', $host);
        $path = rtrim((string) ($parts['path'] ?? ''), '/');

        return $host.'|'.($path === '' ? '/' : $path);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function posData(array $invoice): array
    {
        $metadata = $invoice['metadata'] ?? null;

        if (! is_array($metadata) || ! is_array($metadata['posData'] ?? null)) {
            return [];
        }

        return $metadata['posData'];
    }

    /**
     * Which fee year an invoice belongs to — or null when that cannot be
     * established.
     *
     * `posData.year` when it is there (invoices from this phase on carry it),
     * otherwise the creation time: a fee checkout is created inside the year
     * it bills, and the alternative was parsing the year back out of a German
     * sentence in `itemDesc`.
     *
     * NO FALLBACK TO "THIS YEAR". It used to, and that was a guess wearing an
     * answer's clothes: an invoice of unknown vintage would have been adopted
     * into the current fee year and measured against the current fee. Not
     * knowing which year money belongs to is a reason to leave it for a human,
     * not to pick the convenient one.
     *
     * The range check exists for the same reason. Any positive integer used to
     * be believed, so a `posData.year` of 1970 — the exact value this phase
     * spent a guard on elsewhere — was taken at face value.
     *
     * @param  array<string, mixed>  $invoice
     */
    private function invoiceYear(array $invoice): ?int
    {
        $year = $this->posData($invoice)['year'] ?? null;

        if (is_numeric($year) && $this->isPlausibleYear((int) $year)) {
            return (int) $year;
        }

        $createdTime = $invoice['createdTime'] ?? null;

        if (is_numeric($createdTime)) {
            $created = (int) now()->setTimestamp((int) $createdTime)->year;

            return $this->isPlausibleYear($created) ? $created : null;
        }

        return null;
    }

    /**
     * The association's fee years, generously bounded. The upper end allows
     * next year because a checkout started on 31 December is legitimate.
     */
    private function isPlausibleYear(int $year): bool
    {
        return $year >= 2020 && $year <= (int) now()->year + 1;
    }
}
