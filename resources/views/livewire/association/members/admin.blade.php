<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\PaymentEvent;
use App\Support\Board;
use App\Support\NostrAuth;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component
{
    use WithPagination;

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
        'nostrLoggedIn' => 'handleNostrLoggedIn',
    ];

    public function mount(): void
    {
        if (NostrAuth::check()) {
            $this->currentPubkey = NostrAuth::pubkey();
            $this->currentPleb = NostrAuth::user()?->getPleb();
        }

        $this->refreshAccess();
    }

    public function handleNostrLoggedIn($signedEvent = null): void
    {
        $this->currentPubkey = NostrAuth::loginWithSignedEvent($signedEvent);
        $this->currentPleb = NostrAuth::user()?->getPleb();

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
     * Managing members is a board task, so the board list in
     * `config/einundzwanzig.config.current_board` is the single source —
     * the same one `EinundzwanzigPleb::isBoardMember()` and the election
     * and proposal policies use. `App\Support\Board` resolves the configured
     * npubs to hex pubkeys, because a Nostr session only ever carries the
     * hex form. Authorization is re-checked server-side on every sensitive
     * action — gating the view on $isAllowed is cosmetic only, because
     * Livewire exposes every public method as a directly callable endpoint
     * regardless of what the view renders.
     */
    private function isAuthorized(): bool
    {
        return NostrAuth::check()
            && Board::containsPubkey(NostrAuth::pubkey());
    }

    private function ensureAuthorized(): void
    {
        abort_unless($this->isAuthorized(), 403);
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
        $query = EinundzwanzigPleb::query()
            ->with([
                'profile',
                'paymentEvents' => fn ($query) => $query
                    ->where('year', date('Y'))
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
                ->where('year', date('Y'))
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

        $this->showPaidOnly = !$this->showPaidOnly;
        $this->resetPage();
    }

    public function openPaymentModal(int $plebId): void
    {
        $this->ensureAuthorized();

        $this->selectedPlebId = $plebId;
        Flux::modal('payment-details')->show();
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
     * The board path PASSIVE → ACTIVE/HONORARY. It never grants the
     * membership itself — that follows from the paid annual fee.
     *
     * Two guards, both load-bearing:
     * - `application_for` may be null (no application pending, or one that was
     *   already handled). `AssociationStatus::from(null)` is fatal, so nothing
     *   happens at all in that case.
     * - The target status is never allowed to fall below the current one. A
     *   stale application row must not demote an honorary member back to
     *   passive; a demotion is a deliberate act, not a side effect of
     *   confirming an application.
     */
    public function acceptPleb(): void
    {
        $this->ensureAuthorized();

        if (! $this->confirmAcceptId) {
            return;
        }

        $pleb = EinundzwanzigPleb::query()->findOrFail($this->confirmAcceptId);
        $target = AssociationStatus::tryFrom((int) $pleb->application_for);

        if ($pleb->application_for === null || $target === null) {
            $this->confirmAcceptId = null;
            Flux::modal('confirm-accept-pleb')->close();

            return;
        }

        if ($target->value > $pleb->association_status->value) {
            $pleb->association_status = $target;
        }

        $pleb->archived_application_text = $pleb->application_text;
        $pleb->application_for = null;
        $pleb->application_text = null;
        $pleb->save();

        $this->confirmAcceptId = null;
        Flux::modal('confirm-accept-pleb')->close();
    }

    public function deletePleb(): void
    {
        $this->ensureAuthorized();

        if ($this->confirmDeleteId) {
            $pleb = EinundzwanzigPleb::query()->findOrFail($this->confirmDeleteId);
            $pleb->application_for = null;
            $pleb->application_text = null;
            $pleb->save();

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

        $currentYear = (int) date('Y');
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

        $plebs = EinundzwanzigPleb::query()
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
            fputcsv($handle, $headers, ';');

            foreach ($plebs as $pleb) {
                $row = [
                    $pleb->profile?->name ?: $pleb->profile?->display_name ?? '',
                    $pleb->npub,
                    $pleb->email ?? '',
                    $pleb->association_status->label(),
                ];

                $paymentsByYear = $pleb->paymentEvents->keyBy('year');
                foreach ($years as $year) {
                    $payment = $paymentsByYear->get($year);
                    $row[] = $payment ? 'Bezahlt' : 'Offen';
                }

                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        }, 'mitglieder-export-'.date('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    #[Computed]
    public function selectedPleb(): ?EinundzwanzigPleb
    {
        if (! $this->isAuthorized()) {
            return null;
        }

        return EinundzwanzigPleb::with(['paymentEvents'])->find($this->selectedPlebId);
    }
};
?>

<div>
    @if($isAllowed)
        <div class="overflow-hidden rounded-xl border border-border-subtle bg-bg-surface">
            <div class="flex gap-2 border-b border-border-subtle p-4 sm:px-6">
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
                >
                    {{ $showPaidOnly ? 'Alle anzeigen' : 'Nur Bezahlt' }}
                </flux:button>
                <flux:button
                    wire:click="exportCsv"
                    variant="ghost"
                    icon="arrow-down-tray"
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
                <flux:table.column>Beitrag {{ date('Y') }}</flux:table.column>
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
                                    {{ $pleb->profile?->name ?: $pleb->profile?->display_name ?? '' }}
                                </flux:heading>
                                <a
                                    target="_blank"
                                    href="https://nostrudel.ninja/u/{{ $pleb->npub }}"
                                    class="text-xs text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200"
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
                                    size="sm"
                                    icon="banknotes"
                                >
                                        <span class="text-green-500">
                                            {{ number_format($pleb->paymentEvents->first()->amount, 0, ',', '.') }}
                                        </span>
                                </flux:button>
                            @else
                                <flux:text class="text-zinc-500">keine Zahlung vorhanden</flux:text>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex gap-2">
                                <flux:button
                                    wire:click="delete({{ $pleb->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    inset="top bottom"
                                ></flux:button>

                                @if($pleb->application_for)
                                    <flux:button
                                        wire:click="accept({{ $pleb->id }})"
                                        variant="ghost"
                                        size="sm"
                                        icon="check"
                                        inset="top bottom"
                                    ></flux:button>
                                @endif
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
            </flux:table>
        </div>
    @else
        <div class="px-4 sm:px-6 lg:px-8 py-8 w-full mx-auto">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:heading>Mitglieder können nicht bearbeitet werden</flux:heading>
                <p>
                    Zugriff auf die Mitgliederverwaltung ist nur für spezielle autorisierte Benutzer möglich.
                </p>
                <p class="mt-3">
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
                        {{ $this->selectedPleb->profile?->name ?: $this->selectedPleb->profile?->display_name ?? 'Unbekannt' }}
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
