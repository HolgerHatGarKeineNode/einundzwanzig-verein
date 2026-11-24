<?php

use App\Livewire\Forms\ApplicationForm;
use App\Livewire\Forms\ProfileForm;
use App\Livewire\Traits\GuardsActingPleb;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use App\Services\BtcPayClient;
use App\Services\MembershipService;
use App\Services\Nostr\RelayTransport;
use App\Support\NostrAuth;
use App\Traits\NostrFetcherTrait;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use GuardsActingPleb;
    use NostrFetcherTrait;

    public ApplicationForm $form;

    public ProfileForm $profileForm;

    public bool $no = false;

    public bool $showEmail = true;

    public string $fax = '';

    #[Locked]
    public bool $nip05Verified = false;

    #[Locked]
    public ?string $nip05VerifiedHandle = null;

    #[Locked]
    public bool $nip05HandleMismatch = false;

    #[Locked]
    public array $nip05VerifiedHandles = [];

    #[Locked]
    public array $yearsPaid = [];

    #[Locked]
    public ?string $feeEventContent = null;

    #[Locked]
    public $payments;

    #[Locked]
    public ?string $invoiceStatus = null;

    #[Locked]
    public ?string $invoiceStatusLabel = null;

    #[Locked]
    public ?string $invoiceStatusMessage = null;

    #[Locked]
    public string $invoiceStatusVariant = 'info';

    #[Locked]
    public ?string $invoiceExpiresAt = null;

    #[Locked]
    public ?string $invoiceExpiresAtDisplay = null;

    #[Locked]
    public ?string $invoiceExpiresIn = null;

    #[Locked]
    public int $amountToPay = 0;

    #[Locked]
    public bool $currentYearIsPaid = false;

    #[Locked]
    public ?string $currentPubkey = null;

    #[Locked]
    public ?EinundzwanzigPleb $currentPleb = null;

    #[Locked]
    public ?string $qrCode = null;

    /**
     * Where the member came from, so the page can offer the way back. Only
     * `election` means anything; every other value is ignored.
     */
    #[Url(as: 'from', except: null)]
    public ?string $from = null;

    protected $listeners = [
        'nostrLoggedIn' => 'handleNostrLoggedIn',
        'nostrLoggedOut' => 'handleNostrLoggedOut',
    ];

    public function mount(): void
    {
        if (! NostrAuth::check()) {
            return;
        }

        $this->currentPubkey = NostrAuth::pubkey();
        $this->currentPleb = EinundzwanzigPleb::query()
            ->with([
                'paymentEvents' => fn ($query) => $query->where('year', date('Y')),
                'profile',
            ])
            ->where('pubkey', $this->currentPubkey)->first();

        if (! $this->currentPleb) {
            return;
        }

        $this->profileForm->setPleb($this->currentPleb);
        $this->form->setPleb($this->currentPleb);

        if ($this->currentPleb->nip05_handle) {
            $this->nip05VerifiedHandles = $this->getNip05HandlesForPubkey($this->currentPubkey);

            if (count($this->nip05VerifiedHandles) > 0) {
                $this->nip05Verified = true;
                $this->nip05VerifiedHandle = $this->nip05VerifiedHandles[0];

                if (! in_array($this->profileForm->nip05Handle, $this->nip05VerifiedHandles, true)) {
                    $this->nip05HandleMismatch = true;
                }
            }
        }
        $this->no = $this->currentPleb->no_email;
        $this->showEmail = ! $this->no;
        $this->amountToPay = $this->membership()->fee();
        $this->resolveCurrentPaymentEvent();
        $this->loadFeeEventContent();
        $this->loadPaymentStatus();
    }

    public function updatedNo(): void
    {
        $member = $this->actingPleb();

        $this->showEmail = ! $this->no;
        $member->update([
            'no_email' => $this->no,
        ]);
    }

    public function updatingFax(mixed $value): void
    {
        if (! is_string($value)) {
            $this->skipRender();

            abort(422);
        }
    }

    public function updatedFax(): void
    {
        $this->js('alert("Markus Turm wird sich per Fax melden!")');
    }

    public function updatedProfileFormNip05Handle(): void
    {
        $this->profileForm->nip05Handle = strtolower($this->profileForm->nip05Handle);
    }

    public function handleNostrLoggedIn($signedEvent = null): void
    {
        $pubkey = NostrAuth::loginWithSignedEvent($signedEvent);

        $this->currentPubkey = $pubkey;
        $this->currentPleb = EinundzwanzigPleb::query()
            ->with([
                'paymentEvents' => fn ($query) => $query->where('year', date('Y')),
                'profile',
            ])
            ->where('pubkey', $pubkey)->first();

        if (! $this->currentPleb) {
            return;
        }

        $this->profileForm->setPleb($this->currentPleb);
        $this->form->setPleb($this->currentPleb);
        $this->no = $this->currentPleb->no_email;
        $this->showEmail = ! $this->no;

        if ($this->currentPleb->nip05_handle) {
            $this->nip05VerifiedHandles = $this->getNip05HandlesForPubkey($this->currentPubkey);

            if (count($this->nip05VerifiedHandles) > 0) {
                $this->nip05Verified = true;
                $this->nip05VerifiedHandle = $this->nip05VerifiedHandles[0];

                if (! in_array($this->profileForm->nip05Handle, $this->nip05VerifiedHandles, true)) {
                    $this->nip05HandleMismatch = true;
                }
            }
        }

        $this->amountToPay = $this->membership()->fee();
        $this->resolveCurrentPaymentEvent();
        $this->loadFeeEventContent();
        $this->loadPaymentStatus();
    }

    /**
     * Reads the member's own kind 10050 list from the profile relays again, so a
     * member who just set up their DM inbox sees it without waiting for the board.
     * Three tries a minute: each one dials every profile relay.
     */
    public function recheckDmInbox(): void
    {
        $pleb = NostrAuth::check()
            ? EinundzwanzigPleb::query()->notErased()->where('pubkey', NostrAuth::pubkey())->first()
            : null;

        abort_unless($pleb instanceof EinundzwanzigPleb, 403);

        $checked = RateLimiter::attempt("dm-inbox-check:{$pleb->id}", 3, fn (): int => Artisan::call('nostr:sync-dm-relays', ['--member' => $pleb->id]), 60);

        if ($checked === false) {
            Flux::toast(__('Bitte warte eine Minute, bevor du noch einmal prüfst.'), variant: 'warning');

            return;
        }

        Flux::toast(
            $pleb->dmRelays()->exists()
                ? __('Dein Nostr-Postfach ist eingerichtet.')
                : __('Wir haben kein Nostr-Postfach zu deinem Schlüssel gefunden.'),
            variant: $pleb->dmRelays()->exists() ? 'success' : 'warning',
        );
    }

    public function handleNostrLoggedOut(): void
    {
        $this->currentPubkey = null;
        $this->currentPleb = null;
    }

    public function saveEmail(): void
    {
        $this->profileForm->currentPleb = $this->actingPleb();
        $this->profileForm->saveEmail();
    }

    public function saveNip05Handle(): void
    {
        $this->profileForm->currentPleb = $this->actingPleb();
        $this->profileForm->saveNip05Handle();

        // Refresh NIP-05 verification status after saving
        if ($this->currentPleb->nip05_handle) {
            $this->nip05VerifiedHandles = $this->getNip05HandlesForPubkey($this->currentPubkey);

            if (count($this->nip05VerifiedHandles) > 0) {
                $this->nip05Verified = true;
                $this->nip05VerifiedHandle = $this->nip05VerifiedHandles[0];

                if (! in_array($this->profileForm->nip05Handle, $this->nip05VerifiedHandles, true)) {
                    $this->nip05HandleMismatch = true;
                } else {
                    $this->nip05HandleMismatch = false;
                }
            }
        }
    }

    public function pay($comment): mixed
    {
        if (! $this->currentPleb) {
            return redirect()->route('association.profile');
        }

        $member = $this->actingPleb();

        $paymentEvent = $this->resolveCurrentPaymentEvent();
        $this->resetInvoiceMeta();
        $this->syncPaymentEventStatus($paymentEvent);

        try {
            $result = $this->membership()->createInvoice(
                $member,
                (int) date('Y'),
                (string) $comment,
                // Back here after the checkout, still with the way to the election.
                $this->from === 'election' ? route('association.profile', ['from' => 'election']) : null,
            );

            if ($result['created']) {
                $this->applyInvoiceMeta($result['invoice']);
                $this->invoiceStatusVariant = 'info';
                $this->invoiceStatusMessage = 'Rechnung erstellt. Bitte bezahle sie vor Ablauf.';
            }

            return redirect()->away($result['checkout_url']);
        } catch (\Throwable $e) {
            Flux::toast(
                'Fehler beim Erstellen der Rechnung. Bitte versuche es später erneut: '.$e->getMessage(),
                variant: 'danger',
            );

            return redirect()->route('association.profile');
        }
    }

    /**
     * The poll. It reads this database only — has the webhook booked the fee
     * yet? — and never asks BTCPay. Owner decision of 2026-11-24, verbatim:
     * "Nur Abfragen auf unsere DB ob der Webhook schon paid geschrieben hat."
     * A webhook that never arrives is the reconciliation command's job, not
     * every open profile page's.
     *
     * The invoice details shown since the page loaded stay as they are until
     * the fee turns up as paid.
     */
    public function listenForPayment(): void
    {
        if (! $this->currentPleb) {
            return;
        }

        $paymentEvent = $this->resolveCurrentPaymentEvent();

        if ($paymentEvent->paid) {
            if (! $this->currentYearIsPaid) {
                // The webhook may just have made this person a member.
                $this->currentPleb->refresh();
            }

            $this->showPaidStatus();
        }

        $this->currentYearIsPaid = (bool) $paymentEvent->paid;
        $this->loadPayments();
    }

    /**
     * The full status check on page load and login: an open invoice is read
     * from BTCPay once, so its expiry can be shown and a dead checkout freed.
     */
    protected function loadPaymentStatus(): void
    {
        $paymentEvent = $this->resolveCurrentPaymentEvent();

        $this->resetInvoiceMeta();

        $paymentEvent = $this->syncPaymentEventStatus($paymentEvent);
        $this->currentYearIsPaid = (bool) $paymentEvent->paid;

        $this->loadPayments();
    }

    /**
     * Whether the page has anything to wait for: the current fee year is
     * unpaid and an invoice for it is open. Only then is the poll rendered.
     */
    protected function shouldPollPayment(): bool
    {
        return ! $this->currentYearIsPaid
            && (bool) $this->currentPleb?->paymentEvents->last()?->btc_pay_invoice
            && ! in_array($this->invoiceStatus, MembershipService::DEAD_INVOICE_STATUSES, true);
    }

    protected function loadPayments(): void
    {
        $this->payments = $this->currentPleb
            ->paymentEvents()
            ->where('paid', true)
            ->get();
    }

    /**
     * A paid fee is final, so its state comes from this database and BTCPay
     * is not asked. `Settled` is shown without asking because markPaid() is
     * the only writer of `paid`, and it writes only on a settled invoice.
     */
    protected function showPaidStatus(): void
    {
        $this->resetInvoiceMeta();
        $this->invoiceStatus = 'Settled';
        $this->invoiceStatusLabel = $this->statusLabel('Settled');
        $this->invoiceStatusVariant = 'success';
        $this->invoiceStatusMessage = 'Zahlung bestätigt. Danke!';
    }

    protected function resolveCurrentPaymentEvent(): PaymentEvent
    {
        $paymentEvent = $this->membership()->resolvePaymentEvent($this->currentPleb, (int) date('Y'));

        $this->currentPleb->setRelation(
            'paymentEvents',
            $this->currentPleb
                ->paymentEvents()
                ->where('year', date('Y'))
                ->orderBy('id')
                ->get(),
        );

        return $paymentEvent;
    }

    protected function syncPaymentEventStatus(PaymentEvent $paymentEvent): PaymentEvent
    {
        if (! $paymentEvent->btc_pay_invoice) {
            $this->invoiceStatusVariant = 'info';
            $this->invoiceStatusMessage = 'Noch keine Rechnung gestartet. Klicke auf „Sats bezahlen“, um eine neue Rechnung zu erstellen.';
            $this->invoiceStatus = null;
            $this->invoiceStatusLabel = 'Bereit für neue Rechnung';
            $this->invoiceExpiresAt = null;
            $this->invoiceExpiresAtDisplay = null;
            $this->invoiceExpiresIn = null;
            $this->currentYearIsPaid = (bool) $paymentEvent->paid;

            return $paymentEvent;
        }

        if ($paymentEvent->paid) {
            $this->showPaidStatus();
            $this->currentYearIsPaid = true;

            return $paymentEvent;
        }

        try {
            $invoice = $this->fetchInvoice($paymentEvent->btc_pay_invoice);

            $this->applyInvoiceMeta($invoice);

            $status = $invoice['status'] ?? null;
            $this->invoiceStatus = $status;
            $this->invoiceStatusLabel = $this->statusLabel($status);

            if (in_array($status, MembershipService::DEAD_INVOICE_STATUSES, true)) {
                $release = $this->membership()->releaseExpiredInvoice($paymentEvent);
                $paymentEvent = $release['payment_event'];
                $this->currentYearIsPaid = (bool) $paymentEvent->paid;

                if ($release['released']) {
                    $this->invoiceStatusVariant = 'warning';
                    $this->invoiceStatusMessage = 'Die Rechnung ist abgelaufen und wurde entfernt. Starte eine neue Zahlung.';
                } else {
                    /*
                     * A settled fee is never dropped because BTCPay reports the
                     * invoice as expired or invalid — that would destroy the
                     * only proof of payment. The record stays, the state is
                     * reported for manual review instead.
                     */
                    $this->invoiceStatusVariant = 'warning';
                    $this->invoiceStatusMessage = 'Die Rechnung ist abgelaufen oder ungültig, der bezahlte Beitrag bleibt aber erfasst. Bitte melde dich beim Vorstand.';
                }
            } elseif ($status === 'Settled') {
                /*
                 * The invoice fetched above is passed on, so markPaid() checks
                 * the amount and the currency against what was billed without
                 * asking BTCPay a second time. It may REFUSE — a mismatch is
                 * filed for manual review and nothing is booked — which is why
                 * the outcome is read from the result instead of assumed.
                 */
                $result = $this->membership()->markPaid($paymentEvent, $invoice, source: 'volt');
                $paymentEvent = $result['payment_event'];
                $this->currentPleb->refresh();
                $this->currentYearIsPaid = (bool) $paymentEvent->fresh()?->paid;

                if ($result['settled']) {
                    $this->invoiceStatusVariant = 'success';
                    $this->invoiceStatusMessage = 'Zahlung bestätigt. Danke!';
                } else {
                    $this->invoiceStatusVariant = 'warning';
                    $this->invoiceStatusMessage = 'Die Zahlung weicht vom Mitgliedsbeitrag ab und wird geprüft. Bitte melde dich beim Vorstand.';
                }
            } elseif ($status === 'Processing') {
                $this->currentYearIsPaid = $paymentEvent->paid;
                $this->invoiceStatusVariant = 'info';
                $this->invoiceStatusMessage = 'Zahlung eingegangen, wartet auf Bestätigung.';
            } else {
                $this->currentYearIsPaid = $paymentEvent->paid;
                $this->invoiceStatusVariant = 'info';
                $this->invoiceStatusMessage = $this->statusMessage($status);
            }
        } catch (\Throwable $e) {
            $this->resetInvoiceMeta();
            $this->invoiceStatusVariant = 'danger';
            $this->invoiceStatusLabel = 'Status unbekannt';
            $this->invoiceStatusMessage = 'Die Rechnung konnte nicht überprüft werden. Bitte versuche es später erneut.';
            $this->currentYearIsPaid = (bool) $paymentEvent->paid;
        }

        $this->currentPleb->load([
            'paymentEvents' => fn ($query) => $query->where('year', date('Y')),
        ]);

        return $paymentEvent->refresh();
    }

    protected function fetchInvoice(string $invoiceId): array
    {
        return app(BtcPayClient::class)->getInvoice($invoiceId);
    }

    protected function membership(): MembershipService
    {
        return app(MembershipService::class);
    }

    protected function applyInvoiceMeta(array $invoice): void
    {
        $this->invoiceStatus = $invoice['status'] ?? null;
        $this->invoiceStatusLabel = $this->statusLabel($this->invoiceStatus);
        $this->invoiceExpiresAt = $invoice['expirationTime'] ?? null;

        [$this->invoiceExpiresAtDisplay, $this->invoiceExpiresIn] = $this->formatExpiration($this->invoiceExpiresAt);
    }

    protected function formatExpiration(?string $timestamp): array
    {
        if (! $timestamp) {
            return [null, null];
        }

        $expiresAt = is_numeric($timestamp)
            ? Carbon::createFromTimestamp((int) $timestamp, config('app.timezone', 'UTC'))
            : Carbon::parse($timestamp)->setTimezone(config('app.timezone', 'UTC'));

        return [
            $expiresAt->format('d.m.Y H:i'),
            $expiresAt->diffForHumans(null, true, true, 2),
        ];
    }

    protected function statusLabel(?string $status): ?string
    {
        return match ($status) {
            'New' => 'Offene Rechnung',
            'Processing' => 'Zahlung in Bestätigung',
            'Settled' => 'Bezahlt',
            'Expired' => 'Abgelaufen',
            'Invalid' => 'Ungültig',
            default => null,
        };
    }

    protected function statusMessage(?string $status): string
    {
        return match ($status) {
            'Processing' => 'Zahlung eingegangen, warte auf Bestätigung.',
            'Settled' => 'Zahlung bestätigt.',
            'Expired', 'Invalid' => 'Rechnung abgelaufen oder ungültig.',
            default => 'Rechnung ist aktiv. Bitte vor Ablauf bezahlen.',
        };
    }

    protected function resetInvoiceMeta(): void
    {
        $this->invoiceStatus = null;
        $this->invoiceStatusLabel = null;
        $this->invoiceStatusMessage = null;
        $this->invoiceStatusVariant = 'info';
        $this->invoiceExpiresAt = null;
        $this->invoiceExpiresAtDisplay = null;
        $this->invoiceExpiresIn = null;
    }

    /**
     * Records the application. It takes no status argument on purpose — the
     * membership starts with the paid annual fee, not with this click.
     */
    public function save(): void
    {
        $this->form->currentPleb = $this->actingPleb();

        try {
            $this->form->apply();
            $this->currentPleb = $this->currentPleb?->fresh() ?? $this->currentPleb;
            Flux::toast('Antrag erfasst — Mitglied wirst du mit der Zahlung des Jahresbeitrags.', variant: 'success');
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (! $this->form->check) {
                $this->js('alert("Du musst den Statuten zustimmen.")');
            }
            throw $e;
        }
    }

    /**
     * Kept as a public entry point because the component has always exposed it;
     * it now only delegates. The kind-32121 publication is queued by the
     * service, so an unreachable relay no longer decides whether a fee record
     * comes into existence.
     */
    public function createPaymentEvent(): PaymentEvent
    {
        return $this->membership()->resolvePaymentEvent($this->actingPleb(), (int) date('Y'));
    }

    /**
     * The text of the newest kind-32121 fee event, shown above the pay button.
     *
     * The text is the same for every member, so it is cached for everybody:
     * the page used to open a relay WebSocket on every load, with the vendor
     * client's default timeout of 60 s, and keep every event it got in a
     * public property. The read goes through RelayTransport now, whose
     * deadline covers DNS, connect and EOSE together; for ten minutes the
     * cached text is fresh, for the hour after it is served while a refresh
     * runs behind the response. A relay that does not answer is cached as
     * "no text" too, so it costs one wait per ten minutes, not one per page —
     * and the pay button does not depend on it.
     */
    protected function loadFeeEventContent(): void
    {
        $relayUrl = (string) config('services.relay');

        if ($relayUrl === '') {
            $this->feeEventContent = null;

            return;
        }

        $content = Cache::flexible('association:fee-event-content', [600, 3600], function () use ($relayUrl): string {
            $events = app(RelayTransport::class)->fetch($relayUrl, [
                'kinds' => [32121],
                'authors' => ['daf83d92768b5d0005373f83e30d4203c0b747c170449e02fea611a0da125ee6'],
                'limit' => 1,
            ])->events;

            $content = collect($events)->sortByDesc('created_at')->first()['content'] ?? null;

            return is_string($content) ? $content : '';
        });

        $this->feeEventContent = $content !== '' ? $content : null;
    }
}
?>

<div>
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-2xl md:text-3xl text-text-primary font-bold">
            Einundzwanzig ist, was du draus machst
        </h1>
    </div>

    @if($from === 'election')
        <flux:callout icon="hand-raised" class="mb-8">
            <flux:callout.heading>{{ __('Du kommst von der Wahl') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Bezahle unten deinen Mitgliedsbeitrag. Danach geht es zurück zur Wahl.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('association.election')" icon="arrow-left" class="min-h-11" wire:navigate>{{ __('Zurück zur Wahl') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="flex flex-col md:flex-row gap-6">
        <!-- Membership Benefits Section -->
        <flux:card class="w-full md:w-1/3">
            <div class="flex max-md:flex-col items-start">
                <div class="flex-1 max-md:pt-6 self-stretch">
                    <flux:heading size="xl" level="1">Vorteile deiner Mitgliedschaft</flux:heading>
                    <flux:separator variant="subtle" class="mb-6"/>

                    <!-- Kompakte Vorteils-Liste (Details & Einrichtung auf der Vorteile-Seite) -->
                    <ul class="space-y-4">
                        <li class="flex items-start gap-3">
                            <div
                                class="shrink-0 w-9 h-9 rounded-full bg-amber-100 dark:bg-amber-900/60 flex items-center justify-center">
                                <flux:icon.bolt class="size-5 text-amber-600 dark:text-amber-400" aria-hidden="true"/>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-text-primary">Nostr Relay</h3>
                                <p class="text-xs text-text-secondary">Premium Outbox-Relay von Einundzwanzig.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <div
                                class="shrink-0 w-9 h-9 rounded-full bg-emerald-100 dark:bg-emerald-900/60 flex items-center justify-center">
                                <flux:icon.check-circle class="size-5 text-emerald-600 dark:text-emerald-400" aria-hidden="true"/>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-text-primary">NIP-05 Verifizierung</h3>
                                <p class="text-xs text-text-secondary">Menschenlesbarer, verifizierter Nostr-Name.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <div
                                class="shrink-0 w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-900/60 flex items-center justify-center">
                                <flux:icon.shield-check class="size-5 text-purple-600 dark:text-purple-400" aria-hidden="true"/>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm font-semibold text-text-primary">Lightning Watchtower</h3>
                                <p class="text-xs text-text-secondary">Schutz für deine Lightning Channel.</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <div
                                class="shrink-0 w-9 h-9 rounded-full bg-rose-100 dark:bg-rose-900/60 flex items-center justify-center">
                                <flux:icon.cloud-arrow-up class="size-5 text-rose-600 dark:text-rose-400" aria-hidden="true"/>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-text-primary">Blossom Medienserver</h3>
                                    <flux:badge color="rose" size="sm">NEU</flux:badge>
                                </div>
                                <p class="text-xs text-text-secondary">Eigener Speicher für Bilder &amp; Videos auf Nostr.</p>
                                <p class="text-xs text-text-tertiary mt-0.5">5 GB Speicher · max. 1 GB pro Datei</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <div
                                class="shrink-0 w-9 h-9 rounded-full bg-sky-100 dark:bg-sky-900/60 flex items-center justify-center">
                                <flux:icon.chat-bubble-left-right class="size-5 text-sky-600 dark:text-sky-400" aria-hidden="true"/>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <h3 class="text-sm font-semibold text-text-primary">Nostr Community-Gruppe</h3>
                                    <flux:badge color="sky" size="sm">NEU</flux:badge>
                                </div>
                                <p class="text-xs text-text-secondary">Schreibe in der Einundzwanzig-Gruppe auf unserem Community-Relay (NIP-29).</p>
                            </div>
                        </li>
                        <li class="flex items-start gap-3">
                            <div
                                class="shrink-0 w-9 h-9 rounded-full bg-indigo-100 dark:bg-indigo-900/60 flex items-center justify-center">
                                <flux:icon.cpu-chip class="size-5 text-indigo-600 dark:text-indigo-300" aria-hidden="true"/>
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-text-primary">Buzz Relay</h3>
                                    <flux:badge color="indigo" size="sm">NEU</flux:badge>
                                    <flux:badge color="yellow" size="sm" icon="beaker">Experimentell</flux:badge>
                                </div>
                                <p class="text-xs text-text-secondary">Arbeitsraum für Menschen und KI-Agenten – Kanäle, Threads, Dateien und Code, komplett auf Nostr.</p>
                                <p class="text-xs text-text-tertiary mt-0.5">Testbetrieb · Desktop App nötig</p>
                            </div>
                        </li>
                    </ul>

                    <flux:button :href="route('association.benefits')" wire:navigate variant="primary" size="sm"
                                 icon:trailing="arrow-right" class="mt-6 w-full">
                        Alle Details &amp; Einrichtung
                    </flux:button>
                </div>
            </div>
        </flux:card>
        <!-- Main Grid Layout -->
        <div class="w-full md:w-2/3 grid grid-cols-1 gap-6">

            @if($currentPleb)
                <!-- Logged-in User Info -->
                {{-- Hier standen `!bg-bg-surface !border-border-subtle` — ersatzlos entfernt,
                     und zwar als TOTE Klassen, nicht als Vereinfachung.

                     Gegenprobe im gebauten CSS am 2026-08-19: weder `.\!bg-bg-surface` noch
                     `.\!border-border-subtle` kommt darin vor, die Klassen haben nie etwas
                     bewirkt. Der Grund ist die Tailwind-Version: das `!`-PRAEFIX ist die alte
                     v3-Schreibweise, in v4 gehoert das Ausrufezeichen HINTER die Utility. Im
                     selben Abgleich erzeugte das Praefix in `mim-pulse-flux` (4.0.7) und
                     `mim-cockpit` (4.1.12) noch CSS, hier unter 4.1.18 nicht mehr. Solche
                     Klassen sind also Zeitbomben: sie funktionieren, bis jemand Tailwind
                     anhebt, und fallen dann lautlos aus.

                     Die Karte holt Hintergrund und Rahmen aus ihrer eigenen publizierten View
                     (`flux/card/index.blade.php`). Bewusst ersatzlos statt umgeschrieben: ein
                     `bg-bg-surface` ohne `!` waere eine NEUE Regel, die vorher nicht galt. --}}
                <flux:card>
                    <div class="flex items-start gap-4">
                        <img
                            class="w-12 h-12 rounded-full shrink-0 border-2 border-border-default"
                            src="{{ $currentPleb->profile?->picture ?? asset('apple-touch-icon.png') }}"
                            alt="Avatar"
                        >
                        <div class="flex-1 min-w-0">
                            <div class="mb-2">
                                <h4 class="font-semibold text-text-primary text-base">
                                    {{ $currentPleb->profile?->display_name ?? $currentPleb->profile?->name ?? 'Unbekannt' }}
                                </h4>
                                <p class="text-xs text-text-tertiary mt-0.5">
                                    @if($currentPleb->profile?->name)
                                        {{ $currentPleb->profile->name }}
                                    @endif
                                </p>
                            </div>
                            <div class="space-y-1 text-xs">
                                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                    <span class="text-text-tertiary shrink-0">Pubkey:</span>
                                    <code
                                        class="bg-bg-elevated px-2 py-0.5 rounded text-text-secondary break-all font-mono">
                                        {{ $currentPleb->pubkey }}
                                    </code>
                                </div>
                                <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                    <span class="text-text-tertiary shrink-0">Npub:</span>
                                    <code
                                        class="bg-bg-elevated px-2 py-0.5 rounded text-text-secondary break-all font-mono text-xs">
                                        {{ $currentPleb->npub }}
                                    </code>
                                </div>
                                @if($currentPleb->nip05_handle)
                                    <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                                        <span class="text-text-tertiary shrink-0">NIP-05:</span>
                                        <code
                                            class="bg-bg-elevated px-2 py-0.5 rounded text-text-secondary break-all font-mono text-xs">
                                            {{ $currentPleb->nip05_handle }}
                                        </code>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </flux:card>

                <flux:card data-test="dm-inbox-check">
                    @php
                        $dmInboxRelays = \App\Models\PlebDmRelay::query()->where('einundzwanzig_pleb_id', $currentPleb->id)->first()?->relays ?? [];
                    @endphp
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <h3 class="flex items-center gap-2 text-base font-semibold text-text-primary">
                                @if ($dmInboxRelays)
                                    <flux:icon.check-circle class="size-5 shrink-0 text-success" aria-hidden="true" />
                                    {{ __('Dein Nostr-Postfach ist eingerichtet') }}
                                @else
                                    <flux:icon.exclamation-triangle class="size-5 shrink-0 text-warning" aria-hidden="true" />
                                    {{ __('Kein Nostr-Postfach gefunden') }}
                                @endif
                            </h3>
                            @if ($dmInboxRelays)
                                <p class="mt-1 text-sm text-text-secondary">{{ __('Nachrichten des Vereins, etwa Einladungen zur Wahl, erreichen dich verschlüsselt über diese Relays:') }}</p>
                                <ul class="mt-2 space-y-1" data-test="dm-inbox-relays">
                                    @foreach ($dmInboxRelays as $relay)
                                        <li><code class="break-all rounded bg-bg-elevated px-2 py-0.5 font-mono text-xs text-text-secondary">{{ $relay }}</code></li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="mt-1 text-sm text-text-secondary">{{ __('Ohne Postfach erreichen dich keine Nachrichten des Vereins, auch keine Einladungen zur Wahl. Lege in deiner Nostr-App die Relays für Direktnachrichten fest (oft „DM-Relays“ oder „Messaging-Relays“ in den Einstellungen, z. B. in Amethyst, 0xchat oder Coracle) und prüfe dann hier noch einmal.') }}</p>
                            @endif
                        </div>
                        <flux:button wire:click="recheckDmInbox" wire:loading.attr="disabled" wire:target="recheckDmInbox" icon="arrow-path" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="dm-inbox-recheck">{{ __('Jetzt prüfen') }}</flux:button>
                    </div>
                </flux:card>
            @endif

            <!-- Status Section -->
            <flux:card>
                @if(!$currentPleb)
                    <!-- Nostr Login Apps Section -->
                    <div class="space-y-4 mb-8">
                        <h3 class="text-lg md:text-xl text-text-tertiary italic mb-4">
                            Empfohlene Nostr Login und Signer-Apps
                        </h3>

                        <!-- Grid of App Cards -->
                        <div class="grid grid-cols-1 xl:grid-cols-2 2xl:grid-cols-4 gap-4">
                            <flux:card class="h-full">
                                <div class="flex flex-col h-full gap-3 p-1">
                                    <div class="flex-1 min-w-0">
                                        <a class="font-semibold text-text-primary hover:text-amber-500 dark:hover:text-amber-400 transition-colors block"
                                           href="https://github.com/greenart7c3/Amber">
                                            Amber
                                        </a>
                                        <p class="text-sm text-text-secondary mt-2 leading-relaxed">
                                            Perfekt für mobile Android Geräte. Eine App, in der man alle Keys/nsecs
                                            verwalten kann.
                                        </p>
                                    </div>
                                    <div class="flex items-start sm:items-center">
                                        <flux:badge color="green">Android</flux:badge>
                                    </div>
                                </div>
                            </flux:card>

                            <flux:card class="h-full">
                                <div class="flex flex-col h-full gap-3 p-1">
                                    <div class="flex-1 min-w-0">
                                        <a class="font-semibold text-text-primary hover:text-amber-500 dark:hover:text-amber-400 transition-colors block"
                                           href="https://addons.mozilla.org/en-US/firefox/addon/alby/">
                                            Alby - Bitcoin Lightning Wallet & Nostr
                                        </a>
                                        <p class="text-sm text-text-secondary mt-2 leading-relaxed">
                                            Browser-Erweiterung in die man seinen Key/nsec eingeben kann. Pro Alby-Konto
                                            ein nsec.
                                        </p>
                                    </div>
                                    <div class="flex items-start sm:items-center">
                                        <flux:badge color="green">Browser Chrome/Firefox</flux:badge>
                                    </div>
                                </div>
                            </flux:card>

                            <flux:card class="h-full">
                                <div class="flex flex-col h-full gap-3 p-1">
                                    <div class="flex-1 min-w-0">
                                        <a class="font-semibold text-text-primary hover:text-amber-500 dark:hover:text-amber-400 transition-colors block"
                                           href="https://chromewebstore.google.com/detail/nos2x/kpgefcfmnafjgpblomihpgmejjdanjjp">
                                            nos2x
                                        </a>
                                        <p class="text-sm text-text-secondary mt-2 leading-relaxed">
                                            Browser-Erweiterung für Chrome Browser. Multi-Key fähig.
                                        </p>
                                    </div>
                                    <div class="flex items-start sm:items-center">
                                        <flux:badge color="green">Browser Chrome</flux:badge>
                                    </div>
                                </div>
                            </flux:card>

                            <flux:card class="h-full">
                                <div class="flex flex-col h-full gap-3 p-1">
                                    <div class="flex-1 min-w-0">
                                        <a class="font-semibold text-text-primary hover:text-amber-500 dark:hover:text-amber-400 transition-colors block"
                                           href="https://addons.mozilla.org/en-US/firefox/addon/nos2x-fox/">
                                            nos2x-fox
                                        </a>
                                        <p class="text-sm text-text-secondary mt-2 leading-relaxed">
                                            Browser-Erweiterung für Firefox Browser. Multi-Key fähig.
                                        </p>
                                    </div>
                                    <div class="flex items-start sm:items-center">
                                        <flux:badge color="green">Browser Firefox</flux:badge>
                                    </div>
                                </div>
                            </flux:card>
                        </div>

                        <!-- Screenshots Gallery -->
                        <div class="mt-8">
                            <h3 class="text-lg md:text-xl text-text-primary font-semibold mb-4">
                                Amber App - Nostr Signer
                            </h3>

                            <!-- Responsive Gallery Grid -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                                <!-- Screenshot 1 -->
                                <a href="{{ asset('img/1.png') }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="group block">
                                    <div
                                        class="relative overflow-hidden rounded-lg border border-border-default bg-bg-surface shadow-sm hover:shadow-md hover:shadow-amber-500/10 transition-all duration-300">
                                        <img src="{{ asset('img/1.png') }}"
                                             alt="Amber App Screenshot 1"
                                             loading="lazy"
                                             class="w-full h-auto object-cover aspect-9/16 md:aspect-9/18 group-hover:scale-105 transition-transform duration-300">
                                        <div
                                            class="absolute inset-0 bg-linear-to-t from-zinc-900/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                            <div class="absolute top-3 left-3 right-3">
                                                <h4 class="text-white text-sm md:text-base font-semibold drop-shadow-lg">
                                                    Startseite
                                                </h4>
                                            </div>
                                            <div class="absolute bottom-3 left-3 right-3">
                                                <div class="flex items-center gap-2">
                                                    <flux:icon.arrow-top-right-on-square class="size-5 text-white text-sm" aria-hidden="true"/>
                                                    <span class="text-white text-xs font-medium">Vollbild</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>

                                <!-- Screenshot 2 -->
                                <a href="{{ asset('img/2.png') }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="group block">
                                    <div
                                        class="relative overflow-hidden rounded-lg border border-border-default bg-bg-surface shadow-sm hover:shadow-md hover:shadow-amber-500/10 transition-all duration-300">
                                        <img src="{{ asset('img/2.png') }}"
                                             alt="Amber App Screenshot 2"
                                             loading="lazy"
                                             class="w-full h-auto object-cover aspect-9/16 md:aspect-9/18 group-hover:scale-105 transition-transform duration-300">
                                        <div
                                            class="absolute inset-0 bg-linear-to-t from-zinc-900/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                            <div class="absolute top-3 left-3 right-3">
                                                <h4 class="text-white text-sm md:text-base font-semibold drop-shadow-lg">
                                                    Profileinstellungen
                                                </h4>
                                            </div>
                                            <div class="absolute bottom-3 left-3 right-3">
                                                <div class="flex items-center gap-2">
                                                    <flux:icon.arrow-top-right-on-square class="size-5 text-white text-sm" aria-hidden="true"/>
                                                    <span class="text-white text-xs font-medium">Vollbild</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>

                                <!-- Screenshot 3 -->
                                <a href="{{ asset('img/3.png') }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="group block">
                                    <div
                                        class="relative overflow-hidden rounded-lg border border-border-default bg-bg-surface shadow-sm hover:shadow-md hover:shadow-amber-500/10 transition-all duration-300">
                                        <img src="{{ asset('img/3.png') }}"
                                             alt="Amber App Screenshot 3"
                                             loading="lazy"
                                             class="w-full h-auto object-cover aspect-9/16 md:aspect-9/18 group-hover:scale-105 transition-transform duration-300">
                                        <div
                                            class="absolute inset-0 bg-linear-to-t from-zinc-900/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                            <div class="absolute top-3 left-3 right-3">
                                                <h4 class="text-white text-sm md:text-base font-semibold drop-shadow-lg">
                                                    Keyverwaltung
                                                </h4>
                                            </div>
                                            <div class="absolute bottom-3 left-3 right-3">
                                                <div class="flex items-center gap-2">
                                                    <flux:icon.arrow-top-right-on-square class="size-5 text-white text-sm" aria-hidden="true"/>
                                                    <span class="text-white text-xs font-medium">Vollbild</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>

                                <!-- Screenshot 4 -->
                                <a href="{{ asset('img/4.png') }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   class="group block">
                                    <div
                                        class="relative overflow-hidden rounded-lg border border-border-default bg-bg-surface shadow-sm hover:shadow-md hover:shadow-amber-500/10 transition-all duration-300">
                                        <img src="{{ asset('img/4.png') }}"
                                             alt="Amber App Screenshot 4"
                                             loading="lazy"
                                             class="w-full h-auto object-cover aspect-9/16 md:aspect-9/18 group-hover:scale-105 transition-transform duration-300">
                                        <div
                                            class="absolute inset-0 bg-linear-to-t from-zinc-900/60 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                                            <div class="absolute top-3 left-3 right-3">
                                                <h4 class="text-white text-sm md:text-base font-semibold drop-shadow-lg">
                                                    Connect/nsec-Bunker
                                                </h4>
                                            </div>
                                            <div class="absolute bottom-3 left-3 right-3">
                                                <div class="flex items-center gap-2">
                                                    <flux:icon.arrow-top-right-on-square class="size-5 text-white text-sm" aria-hidden="true"/>
                                                    <span class="text-white text-xs font-medium">Vollbild</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>

                        <!-- User Profile Display -->
                        <div class="mt-6">
                            <template x-if="$store.nostr.user">
                                <div class="flex items-center gap-4 p-4 bg-bg-elevated rounded-lg">
                                    <img class="w-12 h-12 rounded-full"
                                         x-bind:src="$store.nostr.user.picture || '{{ asset('apple-touch-icon.png') }}'"
                                         alt="Avatar">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="truncate text-lg leading-snug text-text-primary font-bold"
                                            x-text="$store.nostr.user.display_name"></h3>
                                        <div class="truncate text-sm text-text-tertiary"
                                             x-text="$store.nostr.user.name"></div>
                                    </div>
                                </div>
                            </template>

                            @if($currentPubkey && $currentPleb && $currentPleb->association_status->value < 2)
                                <flux:card class="mt-4">
                                    <div class="flex items-start gap-3">
                                        <svg class="shrink-0 fill-current text-green-500 mt-0.5" width="16" height="16"
                                             viewBox="0 0 16 16">
                                            <path
                                                d="M8 0C3.6 0 0 3.6 0 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zM7 11.4L3.6 8 5 6.6l2 2 4-4L12.4 6 7 11.4z"></path>
                                        </svg>
                                        <p class="text-sm text-text-secondary">Profil in der Datenbank
                                            vorhanden.</p>
                                    </div>
                                </flux:card>
                            @endif
                        </div>
                    </div>
                @endif

                @if($currentPubkey && $currentPleb && !$currentPleb->application_for && $currentPleb->association_status->value < 2)
                    <!-- Membership Registration Section -->
                    <div class="space-y-4 py-6">
                        <div>
                            <h3 class="text-xl md:text-2xl text-text-primary font-bold mb-2">
                                Einundzwanzig Mitglied werden
                            </h3>
                            <p class="text-sm text-text-secondary">
                                Nur Personen können Mitglied werden und zahlen 21.000 Satoshis im Jahr.
                                <a href="https://einundzwanzig.space/verein/"
                                   class="text-amber-500 hover:text-amber-600 dark:hover:text-amber-400 font-medium">
                                    Firmen melden sich bitte direkt an den Vorstand.
                                </a>
                            </p>
                        </div>

                        @if($currentPleb->applied_at)
                            <flux:callout variant="warning" icon="clock">
                                <flux:callout.heading>Antrag erfasst — noch kein Mitglied</flux:callout.heading>
                                <flux:callout.text>
                                    Deinem Antrag vom {{ $currentPleb->applied_at->format('d.m.Y') }} fehlt nur noch
                                    die Zahlung: Die Mitgliedschaft beginnt mit dem Eingang des Jahresbeitrags,
                                    nicht mit dem Antrag. Den Beitrag zahlst du weiter unten unter
                                    „Mitgliedsbeitrag“.
                                </flux:callout.text>
                            </flux:callout>
                        @endif

                        <div class="flex flex-col gap-4 max-w-2xl">
                            <flux:field variant="inline">
                                <flux:checkbox wire:model="form.check" label="Ich stimme den Vereins-Statuten zu"/>
                                <flux:error name="form.check"/>
                            </flux:field>
                            <p class="text-sm text-text-secondary">
                                Der Antrag erfasst deine Daten und deine Zustimmung zu den Statuten. Mitglied
                                wirst du erst mit der Zahlung des Jahresbeitrags — der Vorstand muss nichts
                                genehmigen.
                            </p>
                            <div class="flex flex-col sm:flex-row gap-3">
                                <flux:button wire:click="save" variant="primary">
                                    Antrag mit deinem aktuellen Nostr-Profil stellen
                                </flux:button>
                                <flux:button href="https://einundzwanzig.space/verein/" target="_blank"
                                             variant="outline">
                                    Statuten ansehen
                                </flux:button>
                            </div>
                        </div>
                    </div>
                @endif

                @if($currentPubkey)
                    <!-- Email Settings Section -->
                    <div class="py-6">
                        <flux:callout variant="warning" class="mb-6">
                            <div class="space-y-4">
                                <p class="font-medium text-text-primary">
                                    Falls du möchtest, kannst du hier eine E-Mail Adresse hinterlegen, damit der Verein
                                    dich darüber informieren kann, wenn es Neuigkeiten gibt.
                                </p>
                                <p class="text-sm text-text-secondary">
                                    Am besten eine anonymisierte E-Mail Adresse verwenden. Wir sichern diese Adresse
                                    AES-256 verschlüsselt in der Datenbank ab.
                                </p>
                            </div>
                        </flux:callout>

                        <div class="space-y-4 max-w-2xl">
                            <flux:field variant="inline">
                                <flux:checkbox
                                    wire:model.live="no"
                                    label="Keine E-Mail Adresse angeben"
                                    description="Ich informiere mich selbst in der News Sektion und gebe keine E-Mail Adresse raus."/>
                                <flux:error name="no"/>
                            </flux:field>

                            @if(!$no)
                                <div wire:key="showEmail" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <flux:field>
                                        <flux:label>Fax-Nummer</flux:label>
                                        <flux:input wire:model.live.debounce="fax" placeholder="Fax-Nummer"/>
                                        <flux:error name="fax"/>
                                    </flux:field>

                                    <flux:field>
                                        <flux:label>E-Mail Adresse</flux:label>
                                        <flux:input
                                            type="email"
                                            wire:model.live.debounce="profileForm.email"
                                            placeholder="E-Mail Adresse"
                                            wire:dirty.class="border-amber-500 dark:border-amber-400"/>
                                        <flux:error name="profileForm.email"/>
                                        <div wire:dirty wire:target="profileForm.email" class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                            Nicht gespeichert...
                                        </div>
                                    </flux:field>
                                </div>

                                <div wire:key="showSave">
                                    <flux:button wire:click="saveEmail" wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="saveEmail">Speichern</span>
                                        <span wire:loading wire:target="saveEmail">Speichert...</span>
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </flux:card>

            <!-- Active Member Status -->
            @if($currentPleb && $currentPleb->association_status->value > 1)
                <flux:card>
                    <div class="flex items-start gap-3">
                        <svg class="shrink-0 fill-current text-green-500 mt-0.5" width="16" height="16"
                             viewBox="0 0 16 16">
                            <path
                                d="M8 0C3.6 0 0 3.6 0 8s3.6 8 8 8 8-3.6 8-8-3.6-8-8-8zm0 12c-.6 0-1-.4-1-1s.4-1 1-1 1 .4 1 1-.4 1-1 1zm1-3H7V4h2v5z"></path>
                        </svg>
                        <div>
                            <p class="font-medium text-text-primary">
                                @if($currentYearIsPaid)
                                    <span class="text-green-600 dark:text-green-400">Du bist derzeit ein Mitglied des Vereins. Das aktuelle Jahr ist bezahlt.</span>
                                @else
                                    <span class="text-amber-600 dark:text-amber-400">Du wirst nach Zahlung des Vereinsbeitrages zum Mitglied. Das aktuelle Jahr ist noch nicht bezahlt.</span>
                                @endif
                            </p>
                        </div>
                    </div>
                </flux:card>
            @endif

            {{--
                Payment Section — also open to applicants who are not members yet
                ($applied_at set, status still DEFAULT). Since the paid fee is what
                constitutes the membership, gating this on "status > 1" alone would
                deadlock every new application. The gate is cosmetic either way:
                Livewire exposes pay()/createPaymentEvent() directly regardless.
            --}}
            @if($currentPleb && ($currentPleb->association_status->value > 1 || $currentPleb->applied_at))
                <flux:card>
                    @if($this->shouldPollPayment())
                        <div wire:poll.20s="listenForPayment"></div>
                    @endif
                    <div class="space-y-6">
                        <!-- Payment Info -->
                        <div>
                            <h3 class="text-xl md:text-2xl text-text-primary font-bold mb-4">
                                Mitgliedsbeitrag
                            </h3>

                            @if($currentPleb->paymentEvents->last()?->event_id)
                                <flux:callout variant="info" class="mb-6">
                                    <p class="text-sm">
                                        Nostr Event für die Zahlung des Mitgliedsbeitrags:
                                        <span
                                            class="block mt-2 font-mono text-xs break-all">{{ $currentPleb->paymentEvents->last()?->event_id }}</span>
                                    </p>
                                </flux:callout>
                            @endif

                            @if($invoiceStatusMessage)
                                <flux:callout variant="{{ $invoiceStatusVariant }}" class="mb-6">
                                    <div class="flex items-start gap-3">
                                        <flux:icon.information-circle class="size-5 mt-1" aria-hidden="true"/>
                                        <div class="space-y-1">
                                            <p class="font-semibold text-text-primary">
                                                {{ $invoiceStatusLabel ?? 'Rechnungsstatus' }}
                                                @if($invoiceStatus)
                                                    <span class="text-text-secondary font-normal">({{ $invoiceStatus }})</span>
                                                @endif
                                            </p>
                                            <p class="text-sm text-text-secondary">
                                                {{ $invoiceStatusMessage }}
                                            </p>
                                            @if($invoiceExpiresIn && in_array($invoiceStatus, ['New', 'Processing'], true))
                                                <p class="text-xs text-text-tertiary">
                                                    Gültig noch {{ $invoiceExpiresIn }}
                                                    @if($invoiceExpiresAtDisplay)
                                                        (bis {{ $invoiceExpiresAtDisplay }})
                                                    @endif
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </flux:callout>
                            @endif

                            @if($feeEventContent)
                                <p class="text-text-secondary mb-6">{{ $feeEventContent }}</p>
                            @endif

                            <!-- Payment Button -->
                            <div class="flex justify-center py-6">
                                @if(!$currentYearIsPaid)
                                    <flux:button
                                        wire:click="pay('{{ date('Y') }}:{{ $currentPubkey }}')"
                                        variant="primary"
                                        class="min-h-12 px-8 text-xl">
                                        <flux:icon.bolt class="size-5 mr-2" aria-hidden="true"/>
                                        {{ __(':amount Sats bezahlen', ['amount' => $amountToPay]) }}
                                    </flux:button>
                                @else
                                    <flux:button disabled variant="primary" color="green" class="min-h-12 px-8 text-xl">
                                        <flux:icon.check-circle class="size-5 mr-2" aria-hidden="true"/>
                                        Aktuelles Jahr bezahlt
                                    </flux:button>
                                @endif
                            </div>
                        </div>

                        <!-- Payment History -->
                        @if($payments && count($payments) > 0)
                            <div class="pt-6 border-t border-border-default">
                                <h4 class="text-lg md:text-xl text-text-primary font-bold mb-4">
                                    Bisherige Zahlungen
                                </h4>

                                <!-- Desktop Table -->
                                <div class="hidden md:block overflow-x-auto">
                                    <table class="table-auto w-full">
                                        <thead
                                            class="text-xs font-semibold uppercase text-text-tertiary border-b border-border-default">
                                        <tr>
                                            <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap text-left">
                                                <div class="font-semibold">Satoshis</div>
                                            </th>
                                            <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap text-left">
                                                <div class="font-semibold">Jahr</div>
                                            </th>
                                            <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap text-left">
                                                <div class="font-semibold">Event-ID</div>
                                            </th>
                                            <th class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap text-left">
                                                <div class="font-semibold">Quittung</div>
                                            </th>
                                        </tr>
                                        </thead>
                                        <tbody class="text-sm divide-y divide-zinc-200 dark:divide-zinc-600">
                                        @foreach($payments as $payment)
                                            <tr>
                                                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                    <div
                                                        class="font-medium text-text-primary">{{ $payment->amount }}</div>
                                                </td>
                                                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                    <div
                                                        class="text-text-primary">{{ $payment->year }}</div>
                                                </td>
                                                <td class="px-2 first:pl-5 last:pr-5 py-3">
                                                    <div
                                                        class="font-mono text-xs text-text-secondary break-all">{{ $payment->event_id }}</div>
                                                </td>
                                                <td class="px-2 first:pl-5 last:pr-5 py-3 whitespace-nowrap">
                                                    @if($payment->btc_pay_invoice)
                                                        <flux:button
                                                            href="{{ app(\App\Services\BtcPayClient::class)->receiptUrl($payment->btc_pay_invoice) }}"
                                                            target="_blank"
                                                            variant="subtle"
                                                            class="min-h-11">
                                                            Quittung
                                                        </flux:button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Mobile Cards -->
                                <div class="md:hidden space-y-4">
                                    @foreach($payments as $payment)
                                        <div
                                            class="bg-bg-surface rounded-lg border border-border-default p-4">
                                            <div class="space-y-3">
                                                <div class="flex justify-between items-center">
                                                    <span class="text-sm font-medium text-text-tertiary">Satoshis</span>
                                                    <span
                                                        class="font-semibold text-text-primary">{{ $payment->amount }}</span>
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <span class="text-sm font-medium text-text-tertiary">Jahr</span>
                                                    <span
                                                        class="text-text-primary">{{ $payment->year }}</span>
                                                </div>
                                                <div>
                                                    <span
                                                        class="text-sm font-medium text-text-tertiary block mb-1">Event-ID</span>
                                                    <span
                                                        class="font-mono text-xs text-text-secondary break-all">{{ $payment->event_id }}</span>
                                                </div>
                                                @if($payment->btc_pay_invoice)
                                                    <flux:button
                                                        href="{{ app(\App\Services\BtcPayClient::class)->receiptUrl($payment->btc_pay_invoice) }}"
                                                        target="_blank"
                                                        variant="subtle"
                                                        class="min-h-11 w-full text-sm">
                                                        Quittung anzeigen
                                                    </flux:button>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                </flux:card>
            @endif
        </div>
    </div>
</div>
