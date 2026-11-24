<?php

use App\Livewire\Traits\WithMessageDates;
use App\Livewire\Traits\WithViewer;
use App\Models\DmRun;
use App\Models\DmSchedule;
use App\Models\Election;
use App\Services\Dm\DirectMessagePause;
use App\Services\Nostr\NotificationKey;
use App\Support\NostrAuth;
use Carbon\CarbonInterface;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The board's message centre: what went out and to whom (Verlauf), a new
 * message from type to confirmed sending (Neue Nachricht), and an
 * election's planned messages with the global pause (Zeitplan).
 *
 * The page keeps the header, the sender-key and pause lines and the tabs;
 * each tab is a child component of its own (`admin-history`,
 * `admin-compose`, `admin-schedule`). The history is mounted while its tab
 * is open; the two writing tabs stay mounted and are shown by `visible`,
 * so a draft survives a switch as it did when its state lived here. The tabs
 * hand back what the page shows of them: `message-sent` opens the started
 * run in the history, `message-run-toggled` keeps the header's primary
 * button, `messages-changed` refreshes the pause line and the tab counts.
 *
 * Board only. Every public method and every computed property here and in
 * each tab resolves the acting board member from the session and answers
 * 403 to anybody else, so a page loaded by a board member stops working the
 * moment the session belongs to somebody who is not.
 *
 * Nothing here talks to a relay: sending is queued work
 * (DispatchBulkDirectMessages → SendDirectMessage).
 */
new
#[Title('Nachrichten an Mitglieder')]
class extends Component
{
    use WithMessageDates;
    use WithViewer;

    private const TABS = ['history', 'compose', 'schedule'];

    /** The runs the history tab lists; its tab count is the failed ones among them. */
    private const HISTORY_LIMIT = 30;

    #[Locked]
    public bool $isGuest = false;

    #[Url(as: 'bereich', except: 'history')]
    public string $tab = 'history';

    /** The run the history tab opens, as the tab last said or a sending just started. */
    #[Locked]
    public ?int $openRunId = null;

    public function mount(): void
    {
        if (! NostrAuth::check()) {
            $this->isGuest = true;

            return;
        }

        $this->boardActor();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'history';
        }
    }

    public function showTab(string $tab): void
    {
        $this->boardActor();

        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'history';
    }

    public function updatedTab(): void
    {
        $this->boardActor();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'history';
        }
    }

    /**
     * The compose tab started a sending: show it, opened, in the history.
     */
    #[On('message-sent')]
    public function messageSent(int $runId): void
    {
        $this->boardActor();

        $this->openRunId = DmRun::query()->findOrFail($runId)->id;
        $this->tab = 'history';
    }

    /**
     * The history tab opened or closed a run; the header follows.
     */
    #[On('message-run-toggled')]
    public function runToggled(?int $runId = null): void
    {
        $this->boardActor();

        $this->openRunId = $runId;
    }

    /**
     * A tab changed the schedule or the pause; the page renders again.
     */
    #[On('messages-changed')]
    public function messagesChanged(): void
    {
        $this->boardActor();
    }

    #[Computed]
    public function pausedAt(): ?CarbonInterface
    {
        $this->boardActor();

        return app(DirectMessagePause::class)->pausedAt();
    }

    /**
     * Whether the sender key (NOSTR_NOTIFICATION_KEY) can seal a message.
     * Without it every Nostr delivery fails as `no_sender_key`, and only the
     * portal inbox copy arrives, so the board is told before it sends.
     * Yes or no only: the key itself never reaches the page.
     */
    #[Computed]
    public function senderKeyUsable(): bool
    {
        $this->boardActor();

        return app(NotificationKey::class)->isUsable();
    }

    /**
     * The runs of the history list with failed work (failed or a stuck
     * claim), counted without loading what the list shows.
     *
     * @return list<int>
     */
    #[Computed]
    public function failedRunIds(): array
    {
        $this->boardActor();

        return DmRun::query()
            ->select('id')
            ->withCount(['deliveries as failed_count' => fn ($query) => $query->failedOrStuck(now())])
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->filter(fn (DmRun $run): bool => $run->failed_count > 0)
            ->pluck('id')
            ->all();
    }

    /**
     * Planned rows that have not gone out yet.
     */
    #[Computed]
    public function upcomingCount(): int
    {
        $this->boardActor();

        return DmSchedule::query()->whereNull('dm_run_id')->count();
    }

    /**
     * Whether there is an election to plan for: then the schedule tab's
     * „Einplanen“ is the primary button, not the header's.
     */
    #[Computed]
    public function hasElection(): bool
    {
        $this->boardActor();

        return Election::query()->exists();
    }
};
?>

@php
    $pausedAt = $isGuest ? null : $this->pausedAt;
@endphp

{{-- pb-32: the floating "Connect with Nostr" button of window.nostr.js sits
     at the bottom of the viewport; the last line must scroll clear of it. --}}
<div class="election-ui relative mx-auto w-full max-w-5xl pb-32">
    @if ($isGuest)
        <div class="max-w-2xl">
            <flux:callout variant="warning" icon="exclamation-circle">
                <flux:callout.heading>{{ __('Melde dich mit Nostr an') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Die Nachrichten an Mitglieder sind dem Vorstand vorbehalten. Melde dich unten in der Navigation an.') }}</flux:callout.text>
            </flux:callout>
        </div>
    @else
        <x-page-header>
            <x-slot:title>{{ __('Nachrichten an Mitglieder') }}</x-slot:title>
                <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
                    {{ __('Was der Verein als Nostr-Direktnachricht verschickt. Jede Nachricht steht zusätzlich im Posteingang des Mitglieds im Portal.') }}
                </p>
            <x-slot:actions>
                @php
                    // One primary action at a time: an open run with failures offers its re-send as the primary one.
                    // On the schedule tab „Einplanen“ is the primary one, as soon as there is an election to plan for.
                    $openRunHasFailures = $tab === 'history' && $openRunId !== null && in_array($openRunId, $this->failedRunIds, true);
                    $scheduleHasPrimary = $tab === 'schedule' && $this->hasElection;
                @endphp
                @if ($tab !== 'compose')
                    <flux:button wire:click="showTab('compose')" icon="pencil-square" :variant="$openRunHasFailures || $scheduleHasPrimary ? 'outline' : 'primary'" class="min-h-11" data-test="start-compose">
                        {{ __('Neue Nachricht') }}
                    </flux:button>
                @endif
            </x-slot:actions>
        </x-page-header>

        @unless ($this->senderKeyUsable)
            {{-- Not a reason to block: the portal inbox copy still arrives, and failed deliveries can be re-sent once the key is set. --}}
            <div role="status" class="mb-8 rounded-r-lg border-l-4 border-danger bg-bg-elevated p-4" data-test="sender-key-missing">
                <p class="flex items-start gap-3 text-base text-text-primary">
                    <flux:icon.key class="mt-0.5 size-5 shrink-0 text-danger" aria-hidden="true" />
                    <span><span class="font-bold">{{ __('Absenderschlüssel fehlt.') }}</span> {{ __('Nachrichten erreichen Mitglieder nur im Portal, nicht per Nostr. Das ist eine Server-Einstellung (NOSTR_NOTIFICATION_KEY).') }}</span>
                </p>
            </div>
        @endunless

        @if ($pausedAt)
            <div role="status" class="mb-8 flex flex-col gap-3 rounded-r-lg border-l-4 border-warning bg-bg-elevated p-4 sm:flex-row sm:items-center sm:justify-between" data-test="paused-line">
                <p class="flex items-start gap-3 text-base text-text-primary">
                    <flux:icon.pause-circle class="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                    <span><span class="font-bold">{{ __('Versand pausiert') }}</span> {{ __('seit :when. Bis zum Fortsetzen geht keine Nachricht raus.', ['when' => $this->longDate($pausedAt)]) }}</span>
                </p>
                @if ($tab !== 'schedule')
                    <flux:button wire:click="showTab('schedule')" variant="outline" class="min-h-11 w-full shrink-0 sm:w-auto">{{ __('Zum Schalter') }}</flux:button>
                @endif
            </div>
        @endif

        @php
            $failedRuns = count($this->failedRunIds);
            $upcomingCount = $this->upcomingCount;
            $tabs = [
                'history' => [__('Verlauf'), $failedRuns > 0 ? trans_choice(':count mit Fehlern|:count mit Fehlern', $failedRuns) : null],
                'compose' => [__('Neue Nachricht'), null],
                'schedule' => [__('Zeitplan'), $upcomingCount > 0 ? __(':count geplant', ['count' => $upcomingCount]) : null],
            ];
        @endphp
        <nav class="flex flex-wrap gap-x-2 border-b border-border-default" aria-label="{{ __('Bereiche') }}">
            @foreach ($tabs as $key => [$tabLabel, $tabCount])
                <button type="button" wire:click="showTab('{{ $key }}')" wire:key="tab-{{ $key }}"
                        @if ($tab === $key) aria-current="page" @endif
                        data-test="tab-{{ $key }}"
                        @class([
                            '-mb-px min-h-11 border-b-2 px-3 text-base focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
                            'border-orange-500 font-bold text-text-primary' => $tab === $key,
                            'border-transparent text-text-secondary hover:text-text-primary' => $tab !== $key,
                        ])>
                    {{ $tabLabel }}@if ($tabCount)<span @class(['ml-2 text-sm font-normal', 'text-danger' => $key === 'history', 'text-text-tertiary' => $key !== 'history'])>{{ $tabCount }}</span>@endif
                </button>
            @endforeach
        </nav>

        <div class="pt-8" data-test="work-area">
            {{-- The writing tabs stay mounted, hidden, so a draft outlives a tab switch; the history is read fresh. --}}
            @if ($tab === 'history')
                <livewire:association.messages.admin-history :open-run-id="$openRunId" key="messages-history" />
            @endif
            <livewire:association.messages.admin-compose :visible="$tab === 'compose'" key="messages-compose" />
            <livewire:association.messages.admin-schedule :visible="$tab === 'schedule'" key="messages-schedule" />
        </div>
    @endif
</div>
