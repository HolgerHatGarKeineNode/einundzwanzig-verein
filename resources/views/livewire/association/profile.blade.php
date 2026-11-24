<?php

use App\Livewire\Forms\ApplicationForm;
use App\Livewire\Forms\ProfileForm;
use App\Livewire\Traits\WithViewer;
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
    use NostrFetcherTrait;
    use WithViewer;

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

<div class="mx-auto w-full max-w-3xl pb-32">
    <x-page-header title="Einundzwanzig ist, was du draus machst" />

    @if($from === 'election')
        <flux:callout icon="hand-raised" class="mb-8">
            <flux:callout.heading>{{ __('Du kommst von der Wahl') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Bezahle unten deinen Mitgliedsbeitrag. Danach geht es zurück zur Wahl.') }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button :href="route('association.election')" icon="arrow-left" class="min-h-11" wire:navigate>{{ __('Zurück zur Wahl') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div class="flex flex-col gap-8">
        @if($currentPleb)
            {{-- Who is logged in. --}}
            <section aria-labelledby="profile-identity" class="rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
                <div class="flex items-start gap-4">
                    <img class="size-12 shrink-0 rounded-full border border-border-default"
                         src="{{ $currentPleb->profile?->picture ?? asset('apple-touch-icon.png') }}"
                         alt="">
                    <div class="min-w-0 flex-1">
                        <h2 id="profile-identity" class="text-xl font-bold leading-tight break-words text-text-primary">
                            <x-pleb-name :pleb="$currentPleb" />
                        </h2>
                        @if(filled($currentPleb->profile?->display_name) && $currentPleb->profile->display_name !== $currentPleb->displayName())
                            <p class="mt-1 text-sm text-text-tertiary">{{ $currentPleb->profile->display_name }}</p>
                        @endif
                    </div>
                </div>
                <dl class="mt-6 grid gap-4 text-base sm:grid-cols-[6rem_minmax(0,1fr)] sm:gap-x-4">
                    <dt class="text-sm text-text-tertiary">Pubkey</dt>
                    <dd class="-mt-3 break-all text-text-secondary sm:mt-0">{{ $currentPleb->pubkey }}</dd>
                    <dt class="text-sm text-text-tertiary">Npub</dt>
                    <dd class="-mt-3 break-all text-text-secondary sm:mt-0">{{ $currentPleb->npub }}</dd>
                    @if($currentPleb->nip05_handle)
                        <dt class="text-sm text-text-tertiary">NIP-05</dt>
                        <dd class="-mt-3 break-all text-text-secondary sm:mt-0">{{ $currentPleb->nip05_handle }}</dd>
                    @endif
                </dl>
            </section>

            <section class="rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6" data-test="dm-inbox-check">
                @php
                    $dmInboxRelays = \App\Models\PlebDmRelay::query()->where('einundzwanzig_pleb_id', $currentPleb->id)->first()?->relays ?? [];
                @endphp
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h2 class="flex items-center gap-2 text-xl font-bold leading-tight text-text-primary">
                            @if ($dmInboxRelays)
                                <flux:icon.check-circle class="size-5 shrink-0 text-success" aria-hidden="true" />
                                {{ __('Dein Nostr-Postfach ist eingerichtet') }}
                            @else
                                <flux:icon.exclamation-triangle class="size-5 shrink-0 text-warning" aria-hidden="true" />
                                {{ __('Kein Nostr-Postfach gefunden') }}
                            @endif
                        </h2>
                        @if ($dmInboxRelays)
                            <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">{{ __('Nachrichten des Vereins, etwa Einladungen zur Wahl, erreichen dich verschlüsselt über diese Relays:') }}</p>
                            <ul class="mt-2 space-y-1" data-test="dm-inbox-relays">
                                @foreach ($dmInboxRelays as $relay)
                                    <li class="break-all text-base text-text-primary">{{ $relay }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">{{ __('Ohne Postfach erreichen dich keine Nachrichten des Vereins, auch keine Einladungen zur Wahl. Lege in deiner Nostr-App die Relays für Direktnachrichten fest (oft „DM-Relays“ oder „Messaging-Relays“ in den Einstellungen, z. B. in Amethyst, 0xchat oder Coracle) und prüfe dann hier noch einmal.') }}</p>
                        @endif
                    </div>
                    <flux:button wire:click="recheckDmInbox" wire:loading.attr="disabled" wire:target="recheckDmInbox" icon="arrow-path" class="min-h-11 w-full shrink-0 sm:w-auto" data-test="dm-inbox-recheck">{{ __('Jetzt prüfen') }}</flux:button>
                </div>
            </section>
        @endif

        @if(!$currentPleb)
            {{-- Not logged in: which signer to use. --}}
            <section aria-labelledby="signer-apps" class="rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
                <h2 id="signer-apps" class="text-xl font-bold leading-tight text-text-primary">Empfohlene Nostr Login und Signer-Apps</h2>
                <ul class="mt-4 divide-y divide-border-default">
                        <li class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <a href="https://github.com/greenart7c3/Amber" class="inline-flex min-h-11 min-w-11 items-center text-base font-semibold text-text-primary underline decoration-text-tertiary underline-offset-4 hover:decoration-text-primary">Amber</a>
                                <span class="text-sm text-text-tertiary">Android</span>
                            </div>
                            <p class="max-w-prose text-base leading-normal text-text-secondary">Perfekt für mobile Android Geräte. Eine App, in der man alle Keys/nsecs verwalten kann.</p>
                        </li>
                        <li class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <a href="https://addons.mozilla.org/en-US/firefox/addon/alby/" class="inline-flex min-h-11 min-w-11 items-center text-base font-semibold text-text-primary underline decoration-text-tertiary underline-offset-4 hover:decoration-text-primary">Alby - Bitcoin Lightning Wallet &amp; Nostr</a>
                                <span class="text-sm text-text-tertiary">Browser Chrome/Firefox</span>
                            </div>
                            <p class="max-w-prose text-base leading-normal text-text-secondary">Browser-Erweiterung in die man seinen Key/nsec eingeben kann. Pro Alby-Konto ein nsec.</p>
                        </li>
                        <li class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <a href="https://chromewebstore.google.com/detail/nos2x/kpgefcfmnafjgpblomihpgmejjdanjjp" class="inline-flex min-h-11 min-w-11 items-center text-base font-semibold text-text-primary underline decoration-text-tertiary underline-offset-4 hover:decoration-text-primary">nos2x</a>
                                <span class="text-sm text-text-tertiary">Browser Chrome</span>
                            </div>
                            <p class="max-w-prose text-base leading-normal text-text-secondary">Browser-Erweiterung für Chrome Browser. Multi-Key fähig.</p>
                        </li>
                        <li class="py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <a href="https://addons.mozilla.org/en-US/firefox/addon/nos2x-fox/" class="inline-flex min-h-11 min-w-11 items-center text-base font-semibold text-text-primary underline decoration-text-tertiary underline-offset-4 hover:decoration-text-primary">nos2x-fox</a>
                                <span class="text-sm text-text-tertiary">Browser Firefox</span>
                            </div>
                            <p class="max-w-prose text-base leading-normal text-text-secondary">Browser-Erweiterung für Firefox Browser. Multi-Key fähig.</p>
                        </li>
                </ul>

                <h3 class="mt-8 text-lg font-bold text-text-primary">Amber App - Nostr Signer</h3>
                <ul class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <li>
                            <a href="{{ asset('img/1.png') }}" target="_blank" rel="noopener noreferrer" class="group block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                <img src="{{ asset('img/1.png') }}" alt="Amber App Screenshot 1: Startseite" loading="lazy"
                                     class="aspect-9/16 w-full rounded-lg border border-border-default bg-bg-elevated object-cover">
                                <span class="mt-2 flex items-center gap-1 text-sm text-text-secondary group-hover:text-text-primary">
                                    Startseite
                                    <flux:icon.arrow-top-right-on-square variant="micro" aria-hidden="true"/>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ asset('img/2.png') }}" target="_blank" rel="noopener noreferrer" class="group block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                <img src="{{ asset('img/2.png') }}" alt="Amber App Screenshot 2: Profileinstellungen" loading="lazy"
                                     class="aspect-9/16 w-full rounded-lg border border-border-default bg-bg-elevated object-cover">
                                <span class="mt-2 flex items-center gap-1 text-sm text-text-secondary group-hover:text-text-primary">
                                    Profileinstellungen
                                    <flux:icon.arrow-top-right-on-square variant="micro" aria-hidden="true"/>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ asset('img/3.png') }}" target="_blank" rel="noopener noreferrer" class="group block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                <img src="{{ asset('img/3.png') }}" alt="Amber App Screenshot 3: Keyverwaltung" loading="lazy"
                                     class="aspect-9/16 w-full rounded-lg border border-border-default bg-bg-elevated object-cover">
                                <span class="mt-2 flex items-center gap-1 text-sm text-text-secondary group-hover:text-text-primary">
                                    Keyverwaltung
                                    <flux:icon.arrow-top-right-on-square variant="micro" aria-hidden="true"/>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a href="{{ asset('img/4.png') }}" target="_blank" rel="noopener noreferrer" class="group block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                <img src="{{ asset('img/4.png') }}" alt="Amber App Screenshot 4: Connect/nsec-Bunker" loading="lazy"
                                     class="aspect-9/16 w-full rounded-lg border border-border-default bg-bg-elevated object-cover">
                                <span class="mt-2 flex items-center gap-1 text-sm text-text-secondary group-hover:text-text-primary">
                                    Connect/nsec-Bunker
                                    <flux:icon.arrow-top-right-on-square variant="micro" aria-hidden="true"/>
                                </span>
                            </a>
                        </li>
                </ul>

                <template x-if="$store.nostr.user">
                    <div class="mt-6 flex items-center gap-4 rounded-lg bg-bg-elevated p-4">
                        <img class="size-12 rounded-full"
                             x-bind:src="$store.nostr.user.picture || '{{ asset('apple-touch-icon.png') }}'"
                             alt="">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-lg font-bold leading-snug text-text-primary" x-text="$store.nostr.user.display_name"></p>
                            <p class="truncate text-sm text-text-tertiary" x-text="$store.nostr.user.name"></p>
                        </div>
                    </div>
                </template>
            </section>
        @endif

        @if($currentPubkey && $currentPleb && !$currentPleb->application_for && $currentPleb->association_status->value < 2)
            {{-- Membership application. --}}
            <section aria-labelledby="become-member" class="rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
                <h2 id="become-member" class="text-xl font-bold leading-tight text-text-primary">Einundzwanzig Mitglied werden</h2>
                <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                    Nur Personen können Mitglied werden und zahlen 21.000 Satoshis im Jahr.
                    <a href="https://einundzwanzig.space/verein/"
                       class="font-semibold text-text-primary underline decoration-text-tertiary underline-offset-4 hover:decoration-text-primary">Firmen melden sich bitte direkt an den Vorstand.</a>
                </p>

                @if($currentPleb->applied_at)
                    <flux:callout variant="warning" icon="clock" class="mt-6">
                        <flux:callout.heading>Antrag erfasst — noch kein Mitglied</flux:callout.heading>
                        <flux:callout.text>
                            Deinem Antrag vom {{ $currentPleb->applied_at->format('d.m.Y') }} fehlt nur noch
                            die Zahlung: Die Mitgliedschaft beginnt mit dem Eingang des Jahresbeitrags,
                            nicht mit dem Antrag. Den Beitrag zahlst du weiter unten unter
                            „Mitgliedsbeitrag“.
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div class="mt-6 flex flex-col gap-4">
                    <flux:field variant="inline">
                        <flux:checkbox wire:model="form.check" label="Ich stimme den Vereins-Statuten zu"/>
                        <flux:error name="form.check"/>
                    </flux:field>
                    <p class="max-w-prose text-base leading-normal text-text-secondary">
                        Der Antrag erfasst deine Daten und deine Zustimmung zu den Statuten. Mitglied
                        wirst du erst mit der Zahlung des Jahresbeitrags — der Vorstand muss nichts
                        genehmigen.
                    </p>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <flux:button wire:click="save" variant="primary" class="min-h-11">
                            Antrag mit deinem aktuellen Nostr-Profil stellen
                        </flux:button>
                        <flux:button href="https://einundzwanzig.space/verein/" target="_blank" variant="outline" class="min-h-11">
                            Statuten ansehen
                        </flux:button>
                    </div>
                </div>
            </section>
        @endif

        @if($currentPubkey)
            {{-- Optional e-mail address. --}}
            <section aria-labelledby="email-settings" class="rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
                <h2 id="email-settings" class="text-xl font-bold leading-tight text-text-primary">E-Mail für Neuigkeiten</h2>
                <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                    Falls du möchtest, kannst du hier eine E-Mail Adresse hinterlegen, damit der Verein
                    dich darüber informieren kann, wenn es Neuigkeiten gibt.
                    Am besten eine anonymisierte E-Mail Adresse verwenden. Wir sichern diese Adresse
                    AES-256 verschlüsselt in der Datenbank ab.
                </p>

                <div class="mt-6 flex flex-col gap-4">
                    <flux:field variant="inline">
                        <flux:checkbox
                            wire:model.live="no"
                            label="Keine E-Mail Adresse angeben"
                            description="Ich informiere mich selbst in der News Sektion und gebe keine E-Mail Adresse raus."/>
                        <flux:error name="no"/>
                    </flux:field>

                    @if(!$no)
                        <div wire:key="showEmail" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                                    wire:dirty.class="border-warning"/>
                                <flux:error name="profileForm.email"/>
                                <p wire:dirty wire:target="profileForm.email" class="text-sm text-warning">
                                    Nicht gespeichert...
                                </p>
                            </flux:field>
                        </div>

                        <div wire:key="showSave">
                            <flux:button wire:click="saveEmail" wire:loading.attr="disabled" class="min-h-11">
                                <span wire:loading.remove wire:target="saveEmail">Speichern</span>
                                <span wire:loading wire:target="saveEmail">Speichert...</span>
                            </flux:button>
                        </div>
                    @endif
                </div>
            </section>
        @endif

        @if($currentPleb && $currentPleb->association_status->value > 1)
            {{-- Membership state in words and icon, never by colour alone. --}}
            @if($currentYearIsPaid)
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.text>Du bist derzeit ein Mitglied des Vereins. Das aktuelle Jahr ist bezahlt.</flux:callout.text>
                </flux:callout>
            @else
                <flux:callout variant="warning" icon="clock">
                    <flux:callout.text>Du wirst nach Zahlung des Vereinsbeitrages zum Mitglied. Das aktuelle Jahr ist noch nicht bezahlt.</flux:callout.text>
                </flux:callout>
            @endif
        @endif

        {{--
            Payment Section — also open to applicants who are not members yet
            ($applied_at set, status still DEFAULT). Since the paid fee is what
            constitutes the membership, gating this on "status > 1" alone would
            deadlock every new application. The gate is cosmetic either way:
            Livewire exposes pay()/createPaymentEvent() directly regardless.
        --}}
        @if($currentPleb && ($currentPleb->association_status->value > 1 || $currentPleb->applied_at))
            <section aria-labelledby="membership-fee" class="rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
                @if($this->shouldPollPayment())
                    <div wire:poll.20s="listenForPayment"></div>
                @endif
                <h2 id="membership-fee" class="text-xl font-bold leading-tight text-text-primary">Mitgliedsbeitrag</h2>

                <div class="mt-4 flex flex-col gap-4">
                    @if($currentPleb->paymentEvents->last()?->event_id)
                        <flux:callout variant="info">
                            <flux:callout.text>
                                Nostr Event für die Zahlung des Mitgliedsbeitrags:
                                <span class="mt-2 block break-all">{{ $currentPleb->paymentEvents->last()?->event_id }}</span>
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    @if($invoiceStatusMessage)
                        <flux:callout variant="{{ $invoiceStatusVariant }}" icon="information-circle">
                            <flux:callout.heading>
                                {{ $invoiceStatusLabel ?? 'Rechnungsstatus' }}
                                @if($invoiceStatus)
                                    <span class="font-normal">({{ $invoiceStatus }})</span>
                                @endif
                            </flux:callout.heading>
                            <flux:callout.text>
                                <p>{{ $invoiceStatusMessage }}</p>
                                @if($invoiceExpiresIn && in_array($invoiceStatus, ['New', 'Processing'], true))
                                    <p class="mt-1">
                                        Gültig noch {{ $invoiceExpiresIn }}
                                        @if($invoiceExpiresAtDisplay)
                                            (bis {{ $invoiceExpiresAtDisplay }})
                                        @endif
                                    </p>
                                @endif
                            </flux:callout.text>
                        </flux:callout>
                    @endif

                    @if($feeEventContent)
                        <p class="max-w-prose text-base leading-normal text-text-secondary">{{ $feeEventContent }}</p>
                    @endif

                    <div>
                        @if(!$currentYearIsPaid)
                            <flux:button
                                wire:click="pay('{{ date('Y') }}:{{ $currentPubkey }}')"
                                variant="primary"
                                icon="bolt"
                                class="min-h-12 w-full px-8 text-lg sm:w-auto">
                                {{ __(':amount Sats bezahlen', ['amount' => $amountToPay]) }}
                            </flux:button>
                        @else
                            <p class="flex items-center gap-2 text-base font-semibold text-success">
                                <flux:icon.check-circle class="size-5 shrink-0" aria-hidden="true"/>
                                Aktuelles Jahr bezahlt
                            </p>
                        @endif
                    </div>
                </div>

                @if($payments && count($payments) > 0)
                    <div class="mt-8 border-t border-border-default pt-6">
                        <h3 class="text-lg font-bold text-text-primary">Bisherige Zahlungen</h3>
                        <ul class="mt-4 divide-y divide-border-default">
                            @foreach($payments as $payment)
                                <li class="grid gap-x-6 gap-y-1 py-4 first:pt-0 sm:grid-cols-[6rem_4rem_minmax(0,1fr)_auto] sm:items-center">
                                    <span class="text-base font-semibold text-text-primary">{{ $payment->amount }} <span class="text-sm font-normal text-text-tertiary">Sats</span></span>
                                    <span class="text-base text-text-primary">{{ $payment->year }}</span>
                                    <span class="break-all text-sm text-text-tertiary"><span class="sr-only">Event-ID: </span>{{ $payment->event_id }}</span>
                                    @if($payment->btc_pay_invoice)
                                        <flux:button
                                            href="{{ app(\App\Services\BtcPayClient::class)->receiptUrl($payment->btc_pay_invoice) }}"
                                            target="_blank"
                                            variant="subtle"
                                            class="min-h-11 justify-self-start">
                                            Quittung
                                        </flux:button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif

        {{-- What the membership brings; details and setup on the benefits page. --}}
        <section aria-labelledby="profile-benefits" class="rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6">
            <h2 id="profile-benefits" class="text-xl font-bold leading-tight text-text-primary">Vorteile deiner Mitgliedschaft</h2>
            <ul class="mt-6 flex flex-col gap-6">
            <x-benefit-item compact icon="bolt" title="Nostr Relay" description="Premium Outbox-Relay von Einundzwanzig."></x-benefit-item>
            <x-benefit-item compact icon="check-badge" title="NIP-05 Verifizierung" description="Menschenlesbarer, verifizierter Nostr-Name."></x-benefit-item>
            <x-benefit-item compact icon="shield-check" title="Lightning Watchtower" description="Schutz für deine Lightning Channel."></x-benefit-item>
            <x-benefit-item compact icon="cloud-arrow-up" title="Blossom Medienserver" description="Eigener Speicher für Bilder & Videos auf Nostr. 5 GB Speicher · max. 1 GB pro Datei"><x-slot:badges><flux:badge size="sm">Neu</flux:badge></x-slot:badges></x-benefit-item>
            <x-benefit-item compact icon="chat-bubble-left-right" title="Nostr Community-Gruppe" description="Schreibe in der Einundzwanzig-Gruppe auf unserem Community-Relay (NIP-29)."><x-slot:badges><flux:badge size="sm">Neu</flux:badge></x-slot:badges></x-benefit-item>
            <x-benefit-item compact icon="cpu-chip" title="Buzz Relay" description="Arbeitsraum für Menschen und KI-Agenten – Kanäle, Threads, Dateien und Code, komplett auf Nostr. Testbetrieb · Desktop App nötig"><x-slot:badges><flux:badge size="sm">Neu</flux:badge> <flux:badge color="amber" size="sm" icon="beaker">Experimentell</flux:badge></x-slot:badges></x-benefit-item>
            </ul>
            <flux:button :href="route('association.benefits')" wire:navigate icon:trailing="arrow-right" class="mt-6 min-h-11">
                Alle Details &amp; Einrichtung
            </flux:button>
        </section>
    </div>
</div>
