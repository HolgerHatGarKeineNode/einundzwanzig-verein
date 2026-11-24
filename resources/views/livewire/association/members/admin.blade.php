<?php

use App\Enums\AssociationStatus;
use App\Exceptions\MemberStatusLockedException;
use App\Livewire\Traits\WithViewer;
use App\Models\DmDelivery;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use App\Services\MembershipService;
use App\Support\NostrAuth;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component
{
    use WithPagination;
    use WithViewer;

    /**
     * Columns the table may be sorted by, mapped to a fully-qualified
     * database column. Whitelisting prevents arbitrary user input from
     * `sort()` reaching `orderBy()` and keeps relation sorts explicit.
     *
     * @var array<string, string>
     */
    private const SORTABLE_COLUMNS = [
        'name' => 'profiles.name',
        'association_status' => 'einundzwanzig_plebs.association_status',
    ];

    #[Locked]
    public bool $isAllowed = false;

    #[Locked]
    public ?string $currentPubkey = null;

    #[Locked]
    public ?EinundzwanzigPleb $currentPleb = null;

    public string $sortBy = 'association_status';

    public string $sortDirection = 'desc';

    #[Locked]
    public ?int $selectedPlebId = null;

    #[Locked]
    public ?int $confirmAcceptId = null;

    #[Locked]
    public ?int $messagesPlebId = null;

    #[Locked]
    public ?int $confirmDeleteId = null;

    public string $search = '';

    public bool $showPaidOnly = false;

    public function updatedSearch(): void
    {
        $this->ensureAuthorized();

        $this->resetPage();
    }

    protected $listeners = [
        'nostrLoggedOut' => 'handleNostrLoggedOut',
    ];

    public function mount(): void
    {
        if (NostrAuth::check()) {
            $this->currentPubkey = NostrAuth::pubkey();
            $this->currentPleb = NostrAuth::user()?->getPleb();
        }

        $this->refreshAccess();
    }

    public function handleNostrLoggedOut(): void
    {
        $this->currentPubkey = null;
        $this->currentPleb = null;
        $this->isAllowed = false;
        unset($this->plebs);
    }

    /**
     * Managing members is a board task (`WithViewer::boardActor()`: logged
     * in, not erased, on the board list in
     * `config/einundzwanzig.config.current_board`). Authorization is
     * re-checked server-side on every sensitive action — gating the view on
     * $isAllowed is cosmetic only, because Livewire exposes every public
     * method as a directly callable endpoint regardless of what the view
     * renders.
     */
    private function isAuthorized(): bool
    {
        return $this->viewerIsBoard();
    }

    private function ensureAuthorized(): void
    {
        $this->boardActor();
    }

    private function refreshAccess(): void
    {
        $this->isAllowed = $this->isAuthorized();
    }

    /**
     * Base query for the member listing. Search matches the unencrypted
     * `profile.name` and `npub` columns only — never the CipherSweet-encrypted
     * `email` column, which cannot be searched with a SQL LIKE.
     */
    private function plebsQuery(): Builder
    {
        /*
         * Geloeschte Mitglieder erscheinen nicht mehr im Vorstands-Screen.
         * Ihr Beitragssatz bleibt bestehen — sie passten sonst weiter auf
         * jeden Filter dieser Liste und stuenden mit ihrem Tombstone in
         * Uebersicht und Export. `notErased()` kapselt seine Bedingung selbst,
         * kollidiert also nicht mit dem `orWhere`-Suchblock weiter unten.
         */
        $query = EinundzwanzigPleb::query()
            ->notErased()
            ->with([
                'profile',
                'paymentEvents' => fn ($query) => $query
                    ->where('year', now()->year)
                    ->where('paid', true),
            ]);

        if ($this->search) {
            $query->where(function ($query) {
                $query->whereHas('profile', function ($query) {
                    $query->whereLike('name', '%'.$this->search.'%');
                })->orWhere('npub', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->showPaidOnly) {
            $query->whereHas('paymentEvents', fn ($query) => $query
                ->where('year', now()->year)
                ->where('paid', true)
            );
        }

        return $this->applySorting($query);
    }

    private function applySorting(Builder $query): Builder
    {
        $column = self::SORTABLE_COLUMNS[$this->sortBy] ?? self::SORTABLE_COLUMNS['association_status'];
        $direction = $this->sortDirection === 'asc' ? 'asc' : 'desc';

        if ($this->sortBy === 'name') {
            return $query
                ->select('einundzwanzig_plebs.*')
                ->leftJoin('profiles', 'profiles.pubkey', '=', 'einundzwanzig_plebs.pubkey')
                ->orderBy('profiles.name', $direction);
        }

        return $query->orderBy($column, $direction);
    }

    #[Computed]
    public function plebs(): LengthAwarePaginator
    {
        $this->ensureAuthorized();

        return $this->plebsQuery()->paginate(25);
    }

    public function sort(string $column): void
    {
        $this->ensureAuthorized();

        if (! array_key_exists($column, self::SORTABLE_COLUMNS)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function togglePaidFilter(): void
    {
        $this->ensureAuthorized();

        $this->showPaidOnly = ! $this->showPaidOnly;
        $this->resetPage();
    }

    public function openPaymentModal(int $plebId): void
    {
        $this->ensureAuthorized();

        $this->selectedPlebId = $plebId;
        Flux::modal('payment-details')->show();
    }

    public function openMessages(int $plebId): void
    {
        $this->ensureAuthorized();

        $this->messagesPlebId = EinundzwanzigPleb::query()->notErased()->findOrFail($plebId)->id;
        unset($this->messagesPleb, $this->memberDeliveries);
        Flux::modal('member-messages')->show();
    }

    public function accept($rowId): void
    {
        $this->ensureAuthorized();

        $this->confirmAcceptId = $rowId;
        Flux::modal('confirm-accept-pleb')->show();
    }

    public function delete($rowId): void
    {
        $this->ensureAuthorized();

        $this->confirmDeleteId = $rowId;
        Flux::modal('confirm-delete-pleb')->show();
    }

    /**
     * Accepting and rejecting are `MembershipService::acceptApplication()` and
     * `rejectApplication()`; this component only authorizes and reports.
     */
    public function acceptPleb(MembershipService $membership): void
    {
        $this->ensureAuthorized();

        if (! $this->confirmAcceptId) {
            return;
        }

        try {
            $membership->acceptApplication(EinundzwanzigPleb::query()->findOrFail($this->confirmAcceptId));
        } catch (MemberStatusLockedException $locked) {
            Flux::toast(__('Die Wahl „:name“ ist angekündigt und noch nicht abgeschlossen. Bis dahin ändert ihr hier keinen Mitgliedsstatus, denn das Wählerverzeichnis hängt daran. Den Antrag nehmt ihr nach der Wahl an. Hat ein Aktiv- oder Ehrenmitglied bar bezahlt, tragt ihr es auf der Wahlseite unter „Wählerverzeichnis“ mit Grund als wahlberechtigt ein.', ['name' => $locked->election->name]), variant: 'danger');
        }

        $this->confirmAcceptId = null;
        Flux::modal('confirm-accept-pleb')->close();
    }

    public function deletePleb(MembershipService $membership): void
    {
        $this->ensureAuthorized();

        if ($this->confirmDeleteId) {
            $membership->rejectApplication(EinundzwanzigPleb::query()->findOrFail($this->confirmDeleteId));

            $this->confirmDeleteId = null;
            Flux::modal('confirm-delete-pleb')->close();
        }
    }

    public function closePaymentModal(): void
    {
        $this->selectedPlebId = null;
        Flux::modal('payment-details')->close();
    }

    public function exportCsv(): StreamedResponse
    {
        $this->ensureAuthorized();

        $currentYear = now()->year;
        $years = PaymentEvent::query()
            ->where('year', '>=', 2025)
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->toArray();

        for ($y = 2025; $y <= $currentYear; $y++) {
            if (! in_array($y, $years)) {
                $years[] = $y;
            }
        }
        sort($years);

        // Wie in plebsQuery(): eine geloeschte Person gehoert in keine
        // Mitgliederdatei, auch nicht in eine heruntergeladene.
        $plebs = EinundzwanzigPleb::query()
            ->notErased()
            ->with([
                'profile',
                'paymentEvents' => fn ($query) => $query->where('paid', true)->where('year', '>=', 2025),
            ])
            ->get();

        return response()->streamDownload(function () use ($plebs, $years) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            $headers = ['Name', 'npub', 'Email', 'Status'];
            foreach ($years as $year) {
                $headers[] = 'Beitrag '.$year;
            }
            fputcsv($handle, $headers, ';', '"', '');

            foreach ($plebs as $pleb) {
                $row = [
                    $this->csvCell($pleb->profile?->label() ?? ''),
                    $this->csvCell((string) $pleb->npub),
                    $this->csvCell($pleb->email ?? ''),
                    $pleb->association_status->label(),
                ];

                $paymentsByYear = $pleb->paymentEvents->keyBy('year');
                foreach ($years as $year) {
                    $payment = $paymentsByYear->get($year);
                    $row[] = $payment ? 'Bezahlt' : 'Offen';
                }

                fputcsv($handle, $row, ';', '"', '');
            }

            fclose($handle);
        }, 'mitglieder-export-'.date('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Keeps a member-controlled value text in a spreadsheet: a cell starting
     * with `=`, `+`, `-`, `@`, a tab or a carriage return would otherwise be
     * evaluated as a formula when the export is opened.
     */
    private function csvCell(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    #[Computed]
    public function selectedPleb(): ?EinundzwanzigPleb
    {
        if (! $this->isAuthorized()) {
            return null;
        }

        return EinundzwanzigPleb::with(['paymentEvents'])->find($this->selectedPlebId);
    }

    #[Computed]
    public function messagesPleb(): ?EinundzwanzigPleb
    {
        $this->ensureAuthorized();

        return $this->messagesPlebId === null ? null : EinundzwanzigPleb::with('profile')->find($this->messagesPlebId);
    }

    /**
     * The direct messages that went to the member in the messages dialog,
     * newest first, with where each one stands.
     *
     * @return EloquentCollection<int, DmDelivery>
     */
    #[Computed]
    public function memberDeliveries(): EloquentCollection
    {
        $this->ensureAuthorized();

        return $this->messagesPlebId === null ? new EloquentCollection : DmDelivery::query()
            ->with('run.message')
            ->where('einundzwanzig_pleb_id', $this->messagesPlebId)
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }
};
?>

<div class="mx-auto w-full max-w-5xl pb-32">
    <x-page-header title="Mitglieder" />

    @if($isAllowed)
        <div class="overflow-hidden rounded-lg border border-border-default bg-bg-surface">
            <div class="flex flex-col gap-2 border-b border-border-default p-4 sm:flex-row sm:px-6">
                <flux:input
                        wire:model.live.debounce.300ms="search"
                    placeholder="Suche nach Name oder Npub..."
                    icon="magnifying-glass"
                    class="flex-1"
                />
                <flux:button
                    wire:click="togglePaidFilter"
                    :variant="$showPaidOnly ? 'primary' : 'ghost'"
                    icon="check"
                    class="min-h-11"
                >
                    {{ $showPaidOnly ? 'Alle anzeigen' : 'Nur Bezahlt' }}
                </flux:button>
                <flux:button
                    wire:click="exportCsv"
                    variant="ghost"
                    icon="arrow-down-tray"
                    class="min-h-11"
                >
                    CSV Export
                </flux:button>
            </div>

            <flux:table
                id="einundzwanzig-pleb-table"
                :paginate="$this->plebs"
                container:class="px-4 pt-2 pb-4 sm:px-6"
            >
            <flux:table.columns>
                <flux:table.column class="w-16">Avatar</flux:table.column>
                <flux:table.column
                    sortable
                    :sorted="$sortBy === 'name'"
                    :direction="$sortDirection"
                    wire:click="sort('name')"
                >
                    Name
                </flux:table.column>
                <flux:table.column
                    sortable
                    :sorted="$sortBy === 'association_status'"
                    :direction="$sortDirection"
                    wire:click="sort('association_status')"
                >
                    Aktueller Status
                </flux:table.column>
                <flux:table.column>Beitrag {{ now()->year }}</flux:table.column>
                <flux:table.column>Aktionen</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($this->plebs as $pleb)
                    <flux:table.row :key="$pleb->id">
                        <flux:table.cell>
                            <flux:avatar
                                size="md"
                                circle
                                :src="$pleb->profile?->picture ?? asset('einundzwanzig-alpha.jpg')"
                                :name="$pleb->profile?->name ?? ''"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex flex-col">
                                <flux:heading size="sm">
                                    <x-pleb-name :pleb="$pleb" />
                                </flux:heading>
                                <a
                                    target="_blank"
                                    href="https://nostrudel.ninja/u/{{ $pleb->npub }}"
                                    class="inline-flex min-h-6 items-center text-sm text-text-tertiary underline underline-offset-4 hover:text-text-primary"
                                >
                                    Nostr Profile
                                </a>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge
                                size="sm"
                                :color="match($pleb->association_status) {
                                        AssociationStatus::DEFAULT => 'zinc',
                                        AssociationStatus::PASSIVE => 'yellow',
                                        AssociationStatus::ACTIVE => 'green',
                                        AssociationStatus::HONORARY => 'blue',
                                        default => 'red',
                                    }"
                                inset="top bottom"
                            >
                                {{ $pleb->association_status->label() }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($pleb->paymentEvents->count() > 0 && $pleb->paymentEvents->first()->paid)
                                <flux:button
                                    wire:click="openPaymentModal({{ $pleb->id }})"
                                    variant="ghost"
                                    icon="banknotes"
                                    class="min-h-11"
                                >
                                        <span class="text-success">
                                            {{ number_format($pleb->paymentEvents->first()->amount, 0, ',', '.') }}
                                        </span>
                                </flux:button>
                            @else
                                <flux:text class="text-text-tertiary">keine Zahlung vorhanden</flux:text>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center gap-2">
                                <flux:button
                                    wire:click="openMessages({{ $pleb->id }})"
                                    variant="ghost"
                                    icon="envelope"
                                    class="min-h-11 min-w-11"
                                    :aria-label="__('Nachrichten an :name', ['name' => $pleb->displayName()])"
                                    data-test="member-messages-{{ $pleb->id }}"
                                />
                                <flux:button
                                    wire:click="delete({{ $pleb->id }})"
                                    variant="ghost"
                                    icon="trash"
                                    class="min-h-11 min-w-11"
                                    :aria-label="__('Mitglied :name löschen', ['name' => $pleb->displayName()])"
                                />

                                @if($pleb->application_for)
                                    <flux:button
                                        wire:click="accept({{ $pleb->id }})"
                                        variant="ghost"
                                        icon="check"
                                        class="min-h-11 min-w-11"
                                        :aria-label="__('Mitglied :name akzeptieren', ['name' => $pleb->displayName()])"
                                    />
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
            </flux:table>
        </div>
    @else
        <div>
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:heading>Mitglieder können nicht bearbeitet werden</flux:heading>
                <p>
                    Zugriff auf die Mitgliederverwaltung ist nur für spezielle autorisierte Benutzer möglich.
                </p>
                <p class="mt-4">
                    @if(!NostrAuth::check())
                        Bitte melde dich zunächst mit Nostr an.
                    @else
                        Dein Benutzer-Account ist nicht für diese Funktion autorisiert. Bitte kontaktiere den Vorstand,
                        wenn du Zugriff benötigst.
                    @endif
                </p>
            </flux:callout>
        </div>
    @endif

    <!-- Payment Details Modal -->
    <flux:modal name="payment-details" class="max-w-full">
        @if($this->selectedPleb)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Zahlungsdetails</flux:heading>
                    <flux:subheading class="mt-2">
                        <x-pleb-name :pleb="$this->selectedPleb" />
                    </flux:subheading>
                </div>

                @if($this->selectedPleb->application_text)
                    <flux:callout icon="information-circle" variant="info">
                        {{ $this->selectedPleb->application_text }}
                    </flux:callout>
                @endif

                <section>
                    <flux:heading size="md" class="mb-4">Bisherige Zahlungen</flux:heading>

                    @if($this->selectedPleb->paymentEvents->count() > 0)
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>Satoshis</flux:table.column>
                                <flux:table.column>Jahr</flux:table.column>
                                <flux:table.column>Event-ID</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach($this->selectedPleb->paymentEvents as $payment)
                                    <flux:table.row :key="$payment->id">
                                        <flux:table.cell variant="strong">{{ $payment->amount }}</flux:table.cell>
                                        <flux:table.cell>{{ $payment->year }}</flux:table.cell>
                                        <flux:table.cell class="text-xs">{{ $payment->event_id }}</flux:table.cell>
                                    </flux:table.row>
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    @else
                        <flux:callout variant="info" icon="information-circle">
                            Keine Zahlungen gefunden
                        </flux:callout>
                    @endif
                </section>

                <div class="flex gap-2">
                    <flux:spacer/>
                    <flux:button wire:click="closePaymentModal" variant="primary">Schließen</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Which direct messages went to one member, and where each stands. Small on purpose: the full picture is in the message centre. --}}
    <flux:modal name="member-messages" :closable="false" class="w-full max-w-lg">
        @if ($isAllowed && $messagesPlebId && $this->messagesPleb)
            <div class="space-y-6" data-test="member-messages">
                <div>
                    <flux:heading size="lg" class="break-words">{{ __('Nachrichten an :name', ['name' => \App\Models\MemberProposal::authorNameOf($this->messagesPleb)]) }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Die letzten Direktnachrichten des Vereins an dieses Mitglied. Alles Weitere steht unter „Nachrichten an Mitglieder“.') }}</flux:text>
                </div>
                @if ($this->memberDeliveries->isEmpty())
                    <p class="text-base text-text-secondary">{{ __('Noch keine Nachricht an dieses Mitglied.') }}</p>
                @else
                    <ul class="divide-y divide-border-default border-y border-border-default">
                        @foreach ($this->memberDeliveries as $delivery)
                            <li wire:key="member-delivery-{{ $delivery->id }}" class="py-3" data-test="member-delivery-{{ $delivery->id }}">
                                <p class="flex flex-wrap items-baseline justify-between gap-x-4">
                                    <span class="text-base font-bold text-text-primary">{{ $delivery->run?->message?->type->label() }}</span>
                                    <time datetime="{{ $delivery->created_at->toIso8601String() }}" class="text-sm text-text-tertiary">{{ \App\Support\DisplayTime::of($delivery->created_at)->locale('de')->isoFormat('D. MMM YYYY, HH:mm') }}</time>
                                </p>
                                <p @class(['text-sm', 'text-danger' => $delivery->status->isFailure(), 'text-text-secondary' => ! $delivery->status->isFailure()])>
                                    {{ $delivery->status->label() }}@if ($delivery->seen_at){{ __(', im Portal geöffnet') }}@endif
                                </p>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="flex justify-end">
                    <flux:modal.close>
                        <flux:button variant="ghost" class="min-h-11">{{ __('Schließen') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>

    <!-- Accept Confirmation Modal -->
    <flux:modal name="confirm-accept-pleb" class="min-w-88">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Mitglied akzeptieren</flux:heading>
                <flux:subheading class="mt-2">
                    Bist du sicher, dass du dieses Mitglied akzeptieren möchtest?
                </flux:subheading>
            </div>
            <div class="flex gap-2">
                <flux:spacer/>
                <flux:modal.close>
                    <flux:button variant="ghost">Abbrechen</flux:button>
                </flux:modal.close>
                <flux:button wire:click="acceptPleb" variant="primary">Ja, akzeptieren</flux:button>
            </div>
        </div>
    </flux:modal>

    <!-- Delete Confirmation Modal -->
    <flux:modal name="confirm-delete-pleb" class="min-w-88">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Bewerbung ablehnen</flux:heading>
                <flux:subheading class="mt-2">
                    Bist du sicher, dass du diese Bewerbung ablehnen möchtest?
                </flux:subheading>
            </div>
            <div class="flex gap-2">
                <flux:spacer/>
                <flux:modal.close>
                    <flux:button variant="ghost">Abbrechen</flux:button>
                </flux:modal.close>
                <flux:button wire:click="deletePleb" variant="danger">Ja, ablehnen</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
