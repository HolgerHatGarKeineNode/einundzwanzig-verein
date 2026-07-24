@props([
    'project',
    'currentPleb' => null,
    'eager' => false,
])

@php
    use App\Enums\ProjectProposalStatus;

    $status = $project->status();
    $approvals = $project->boardApprovals();
    $rejections = $project->boardRejections();
    $threshold = App\Models\ProjectProposal::boardVoteThreshold();

    // Die Quorum-Leiste zeigt das fuehrende Lager. Beide gleichzeitig waere auf
    // einer 380px-Karte Laerm — die Detailseite zeigt Zustimmung und Ablehnung.
    $leading = $rejections > $approvals ? 'rejected' : 'approved';
    $filled = min($leading === 'rejected' ? $rejections : $approvals, $threshold);

    $railClass = match ($status) {
        ProjectProposalStatus::Accepted => 'border-l-blue-400',
        ProjectProposalStatus::Supported => 'border-l-green-500',
        ProjectProposalStatus::Rejected => 'border-l-red-400',
        ProjectProposalStatus::InVoting => 'border-l-neutral-400',
    };

    $nostrUser = App\Support\NostrAuth::user();
    $canUpdate = Illuminate\Support\Facades\Gate::forUser($nostrUser)->allows('update', $project);
    $canDelete = Illuminate\Support\Facades\Gate::forUser($nostrUser)->allows('delete', $project);

    $canVote = $currentPleb
        && $currentPleb->isBoardMember()
        && $status === ProjectProposalStatus::InVoting
        && ! $project->hasVoteFrom($currentPleb);
@endphp

<flux:card {{ $attributes->class(['flex gap-4 p-4 border-l-4 transition-colors duration-150 motion-reduce:transition-none', $railClass]) }}>
    <img
        src="{{ $project->getSignedMediaUrl('main', 60, 'preview') }}"
        alt=""
        width="112"
        height="112"
        loading="{{ $eager ? 'eager' : 'lazy' }}"
        fetchpriority="{{ $eager ? 'high' : 'auto' }}"
        decoding="async"
        @class([
            'size-22 sm:size-28 shrink-0 rounded-lg bg-bg-elevated object-cover',
            'opacity-60 grayscale-[35%]' => $status === ProjectProposalStatus::Rejected,
        ])
    >

    <div class="flex-1 min-w-0 flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <span class="status-chip status-chip--{{ $status->value }}">
                <flux:icon :name="$status->icon()" variant="micro" aria-hidden="true"/>
                {{ $status->label() }}
            </span>
            @if($canVote)
                <span class="status-chip border border-orange-700 bg-transparent text-orange-500">
                    <flux:icon name="hand-raised" variant="micro" aria-hidden="true"/>
                    Deine Stimme fehlt
                </span>
            @endif
        </div>

        {{-- min-h-11 = exakt zwei Zeilen bei text-base/leading-snug. Damit stehen
             Sats, Quorum und Aktionen in JEDER Karte einer Reihe auf derselben
             Linie, egal ob der Titel ein- oder zweizeilig laeuft. --}}
        <h3 class="text-base font-bold leading-snug line-clamp-2 min-h-11">
            <a href="{{ route('association.projectSupport.item', ['projectProposal' => $project]) }}"
               class="text-text-primary hover:text-orange-500 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                {{ $project->name }}
            </a>
        </h3>

        <div class="text-[11px] font-semibold uppercase tracking-[0.14em] text-orange-500">
            {{-- Ausgezahlt, sobald erfasst — sonst die beantragte Fördersumme. --}}
            {{ number_format($project->displayedSats(), 0, ',', '.') }} Sats
        </div>

        {{-- Quorum: wie weit ist der Antrag von der absoluten Mehrheit entfernt.
             Ein durchgehender Balken statt einer Pille je Stimme — Segmente würden
             mit wachsendem Vorstand immer schmaler und passen irgendwann nicht mehr
             in die Karte. Der Balken trägt jede Vorstandsgröße. --}}
        @if($status === ProjectProposalStatus::Supported)
            {{-- Gleicher einzeiliger Aufbau wie unten, damit die Zeile in allen
                 Karten auf derselben Hoehe sitzt. --}}
            <div class="flex items-center gap-2"
                 role="img"
                 aria-label="{{ number_format($project->sats_paid, 0, ',', '.') }} Sats ausgezahlt">
                <div class="h-1.5 flex-1 min-w-12 rounded-full bg-green-500" aria-hidden="true"></div>
                <span class="w-28 shrink-0 text-right text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">
                    Ausgezahlt
                </span>
            </div>
        @else
            {{-- „2/4 dafür" bzw. „4/4 dagegen": ohne das Wort läse sich 4/4 bei einer
                 Ablehnung wie vier Zustimmungen. --}}
            <div class="flex items-center gap-2"
                 role="img"
                 aria-label="{{ $filled }} von {{ $threshold }} nötigen Vorstandsstimmen {{ $leading === 'rejected' ? 'gegen' : 'für' }} das Projekt">
                <div class="h-1.5 flex-1 min-w-12 rounded-full bg-neutral-700 overflow-hidden" aria-hidden="true">
                    <div @class([
                            'h-full rounded-full',
                            'bg-red-400' => $leading === 'rejected',
                            'bg-orange-500' => $leading !== 'rejected',
                         ])
                         style="width: {{ $threshold > 0 ? round($filled / $threshold * 100) : 0 }}%"></div>
                </div>
                <span class="w-28 shrink-0 text-right text-[11px] font-semibold uppercase tracking-[0.14em] text-text-tertiary">
                    {{ $filled }}/{{ $threshold }} {{ $leading === 'rejected' ? 'dagegen' : 'dafür' }}
                </span>
            </div>
        @endif

        <div class="hidden sm:flex items-center gap-2 text-[13px] text-text-secondary min-w-0">
            <img class="size-5 rounded-full shrink-0"
                 src="{{ $project->einundzwanzigPleb->profile?->picture }}"
                 onerror="this.src='{{ asset('einundzwanzig-alpha.jpg') }}'"
                 alt=""
                 width="20" height="20" loading="lazy">
            <a href="https://njump.me/{{ $project->einundzwanzigPleb->npub }}"
               target="_blank"
               rel="noopener"
               class="truncate hover:text-orange-500 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                {{ $project->einundzwanzigPleb->profile?->name ?? str($project->einundzwanzigPleb->npub)->limit(20) }}
            </a>
            @if($project->website)
                <span aria-hidden="true">·</span>
                <a href="{{ $project->website }}"
                   target="_blank"
                   rel="noopener"
                   class="truncate hover:text-orange-500 transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                    Webseite
                </a>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-2 mt-auto pt-1">
            {{-- min-h-11: 44px Touch-Ziel, das flux size="sm" allein nicht liefert --}}
            <flux:button
                size="sm"
                class="min-h-11"
                icon="folder-open"
                :href="route('association.projectSupport.item', ['projectProposal' => $project])">
                Öffnen
            </flux:button>

            @if($canUpdate || $canDelete)
                <flux:dropdown position="bottom" align="start">
                    <flux:button size="sm" variant="ghost" class="min-h-11 min-w-11" icon="ellipsis-horizontal"
                                 aria-label="Weitere Aktionen für {{ $project->name }}"/>
                    <flux:menu>
                        @if($canUpdate)
                            <flux:menu.item
                                icon="pencil"
                                :href="route('association.projectSupport.edit', ['projectProposal' => $project])">
                                Bearbeiten
                            </flux:menu.item>
                        @endif
                        @if($canDelete)
                            <flux:menu.item
                                icon="trash"
                                variant="danger"
                                wire:click="$dispatch('confirmDeleteProject', { id: {{ $project->id }} })">
                                Löschen
                            </flux:menu.item>
                        @endif
                    </flux:menu>
                </flux:dropdown>
            @endif
        </div>
    </div>
</flux:card>
