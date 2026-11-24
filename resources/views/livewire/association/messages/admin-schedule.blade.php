<?php

use App\Enums\DmAudience;
use App\Enums\DmMessageType;
use App\Livewire\Traits\WithMessageDrafts;
use App\Livewire\Traits\WithViewer;
use App\Models\DmSchedule;
use App\Models\Election;
use App\Services\Dm\DirectMessagePause;
use App\Services\Dm\DirectMessageTextInvalid;
use App\Services\Dm\ScheduleDirectMessage;
use App\Support\DisplayTime;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * "Zeitplan" of the board's message centre: the global pause, an
 * election's planned messages, and a new planned row with the type's
 * default text.
 *
 * A child of `association.messages.admin`, mounted with the page and shown
 * while its tab is open, so a half-filled row outlives a tab switch.
 * Board only and callable on its own like every Livewire component: every
 * public method and every computed property resolves the acting board
 * member from the session and answers 403 to anybody else. A changed
 * schedule or pause is handed to the page as `messages-changed`, so its
 * pause line and the tab count follow.
 */
new class extends Component
{
    use WithMessageDrafts;
    use WithViewer;


    /**
     * Whether the tab is open. The page keeps this tab mounted while another
     * one is open, so a half-written planned row survives a look at another tab;
     * hidden, it renders nothing.
     */
    #[Reactive]
    public bool $visible = false;

    /** The election timeline messages the schedule offers. */
    private const SCHEDULE_TYPES = [
        DmMessageType::ElectionAnnouncement,
        DmMessageType::NominationDeadline,
        DmMessageType::FeeReminder,
        DmMessageType::ElectionStartsNow,
    ];

    public ?int $scheduleElectionId = null;

    public string $newType = 'election_announcement';

    public string $newAudience = 'members';

    public string $newDate = '';

    public string $newTime = '09:00';

    /** @var array<string, string> */
    public array $newVariables = [];

    public bool $paused = false;

    public function mount(DirectMessagePause $pause): void
    {
        $this->boardActor();

        $this->scheduleElectionId = $this->defaultElection()?->id;
        $this->applyNewTypeDefaults();
        $this->paused = $pause->isPaused();
    }

    public function updatedScheduleElectionId(): void
    {
        $this->boardActor();

        $this->scheduleElectionId = $this->scheduleElection?->id;
        $this->resetErrorBag();
        $this->applyNewTypeDefaults();
    }

    public function updatedNewType(): void
    {
        $this->boardActor();

        if (! in_array(DmMessageType::tryFrom($this->newType), self::SCHEDULE_TYPES, true)) {
            $this->newType = DmMessageType::ElectionAnnouncement->value;
        }

        $this->resetErrorBag();
        $this->applyNewTypeDefaults();
    }

    public function addSchedule(ScheduleDirectMessage $schedule): void
    {
        $actor = $this->boardActor();
        $election = $this->scheduleElection ?? abort(404);

        $type = DmMessageType::tryFrom($this->newType);
        $audience = DmAudience::tryFrom($this->newAudience);

        $this->validate([
            'newDate' => ['required', 'date_format:Y-m-d'],
            'newTime' => ['required', 'date_format:H:i'],
        ], [
            'newDate.*' => __('Wähle den Tag, an dem die Nachricht rausgeht.'),
            'newTime.*' => __('Wähle die Uhrzeit, zum Beispiel 09:00.'),
        ]);

        if (! in_array($type, self::SCHEDULE_TYPES, true) || $audience === null) {
            $this->addError('newType', __('Wähle eine der angebotenen Nachrichten.'));

            return;
        }

        $sendAt = Carbon::createFromFormat('Y-m-d H:i', $this->newDate.' '.$this->newTime, DisplayTime::zone())->utc();

        if ($sendAt->isPast()) {
            $this->addError('newDate', __('Dieser Zeitpunkt ist schon vorbei. Wähle einen späteren.'));

            return;
        }

        try {
            $schedule->handle($election, $type, $audience, $sendAt, $this->newMessageVariables($type), author: $actor);
        } catch (DirectMessageTextInvalid) {
            foreach ($this->problemsOf($type, $type->defaultText(), $this->newMessageVariables($type))['variables'] as $variable => $problem) {
                $this->addError('newVariables.'.$variable, $problem);
            }

            return;
        }

        unset($this->scheduleRows);
        $this->dispatch('messages-changed');
        Flux::toast(__('„:type“ ist eingeplant für :when.', ['type' => $type->label(), 'when' => $this->longDate($sendAt)]), variant: 'success');
    }

    /**
     * Take a planned row out before it has gone out. A row that was already
     * started stays: its run is history.
     */
    public function removeSchedule(int $scheduleId): void
    {
        $this->boardActor();

        $row = DmSchedule::query()->with('message')->findOrFail($scheduleId);

        if ($row->dm_run_id !== null) {
            Flux::toast(__('Diese Nachricht ist schon raus und steht im Verlauf.'), variant: 'danger');

            return;
        }

        $label = $row->message->type->label();
        $message = $row->message;
        $row->delete();

        // The text was written for this row only.
        if (! $message->runs()->exists() && ! DmSchedule::query()->where('dm_message_id', $message->id)->exists()) {
            $message->delete();
        }

        unset($this->scheduleRows);
        $this->dispatch('messages-changed');
        Flux::toast(__('„:type“ ist aus dem Zeitplan entfernt.', ['type' => $label]));
    }

    public function updatedPaused(DirectMessagePause $pause): void
    {
        $this->boardActor();

        if ($this->paused) {
            $pause->pause();
            Flux::toast(__('Versand pausiert. Bis zum Fortsetzen geht keine Nachricht raus.'));
        } else {
            $pause->resume();
            Flux::toast(__('Versand fortgesetzt. Was liegen geblieben ist, geht jetzt raus.'), variant: 'success');
        }

        $this->paused = $pause->isPaused();
        unset($this->pausedAt);
        $this->dispatch('messages-changed');
    }

    #[Computed]
    public function scheduleElection(): ?Election
    {
        $this->boardActor();

        return $this->scheduleElectionId === null ? null : $this->elections->firstWhere('id', $this->scheduleElectionId);
    }

    #[Computed]
    public function pausedAt(): ?CarbonInterface
    {
        $this->boardActor();

        return app(DirectMessagePause::class)->pausedAt();
    }

    /**
     * @return EloquentCollection<int, DmSchedule>
     */
    #[Computed]
    public function scheduleRows(): EloquentCollection
    {
        $this->boardActor();

        return $this->scheduleElectionId === null ? new EloquentCollection : DmSchedule::query()
            ->with(['message', 'election', 'run'])
            ->where('election_event_id', $this->scheduleElectionId)
            ->orderBy('send_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<DmMessageType>
     */
    public function scheduleTypes(): array
    {
        return self::SCHEDULE_TYPES;
    }

    /**
     * @return array<string, string>
     */
    protected function newMessageVariables(DmMessageType $type): array
    {
        return $this->valuesFor($type, $this->newVariables);
    }

    protected function applyNewTypeDefaults(): void
    {
        $type = DmMessageType::tryFrom($this->newType) ?? DmMessageType::ElectionAnnouncement;
        $this->newAudience = $this->defaultAudience($type)->value;
        $this->newVariables = $this->defaultValues($type, $this->scheduleElection);
    }
};
?>

<div>
@if ($visible)
@php
    $election = $this->scheduleElection;
    $newTypeCase = \App\Enums\DmMessageType::tryFrom($newType) ?? \App\Enums\DmMessageType::ElectionAnnouncement;
@endphp

<div class="space-y-12 [&_input]:min-h-11 [&_select]:min-h-11">
    {{-- The pause stops everything, so it comes first and says what it does in either state. --}}
    <section aria-labelledby="pause-heading" @class(['rounded-r-lg border-l-4 bg-bg-elevated p-4 sm:p-6', 'border-warning' => $paused, 'border-success' => ! $paused]) data-test="pause">
        <div class="flex items-start justify-between gap-6">
            <div class="min-w-0">
                <h2 id="pause-heading" class="text-xl font-bold text-text-primary">{{ $paused ? __('Versand pausiert') : __('Versand läuft') }}</h2>
                <p class="mt-1 text-base text-text-secondary" data-test="pause-state">
                    @if ($paused && $this->pausedAt)
                        {{ __('Seit :when geht nichts raus: keine neue, keine geplante und keine wartende Nachricht. Beim Fortsetzen geht Liegengebliebenes raus.', ['when' => $this->longDate($this->pausedAt)]) }}
                    @else
                        {{ __('Nachrichten gehen sofort raus, geplante zu ihrer Zeit. Pausieren hält alles an, auch was schon in der Warteschlange steht.') }}
                    @endif
                </p>
            </div>
            {{-- The visible label is part of the hit area: the 32×20 switch alone is below 44 px. --}}
            <flux:field variant="inline" class="min-h-11 shrink-0 cursor-pointer items-center" data-test="pause-field">
                <flux:label class="min-h-11 cursor-pointer items-center text-base">{{ __('Pausiert') }}</flux:label>
                <flux:switch wire:model.live="paused" data-test="pause-switch" />
            </flux:field>
        </div>
    </section>

    @if ($this->elections->isEmpty())
        <div class="max-w-2xl rounded-lg border border-dashed border-border-default px-6 py-10" data-test="schedule-no-election">
            <p class="text-lg font-semibold text-text-primary">{{ __('Noch keine Wahl angelegt.') }}</p>
            <p class="mt-2 text-base text-text-secondary">{{ __('Geplante Nachrichten gehören zu einer Wahl. Lege sie unter „Wahl leiten“ an.') }}</p>
        </div>
    @else
        <section aria-labelledby="schedule-heading" data-test="schedule">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <h2 id="schedule-heading" class="text-xl font-bold text-text-primary">{{ __('Geplante Nachrichten') }}</h2>
                @if ($this->elections->count() > 1)
                    <div class="w-full sm:w-80">
                        <flux:select wire:model.live="scheduleElectionId" :label="__('Wahl')" data-test="schedule-election">
                            @foreach ($this->elections as $item)
                                <flux:select.option value="{{ $item->id }}">{{ $item->is_test ? __(':name (Probewahl)', ['name' => $item->name]) : $item->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
            </div>
            @if ($election)
                <p class="mt-2 max-w-2xl text-base text-text-secondary">
                    {{ __('Wahl „:name“.', ['name' => $election->name]) }}
                    {{ __('Die Empfänger werden erst beim Senden bestimmt; die Zahl daneben gilt für heute.') }}
                </p>
                @if ($election->is_test)
                    <p class="mt-2 flex items-start gap-2 text-base text-warning" data-test="schedule-test-note">
                        <flux:icon.beaker variant="mini" class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                        {{ __('Probewahl: der Zeitplan sendet ihre Nachrichten nicht. Zum Ausprobieren eignet sich „Test an mich“.') }}
                    </p>
                @endif

                @if ($this->scheduleRows->isEmpty())
                    <p class="mt-6 max-w-2xl text-base text-text-secondary" data-test="schedule-empty">
                        {{ __('Für diese Wahl ist noch nichts geplant. Üblich sind die Ankündigung, die Frist für Kandidaturen, die Erinnerung an den Beitrag und „Die Wahl beginnt jetzt“.') }}
                    </p>
                @else
                    <ul class="mt-6 divide-y divide-border-default border-y border-border-default">
                        @foreach ($this->scheduleRows as $row)
                            <li wire:key="schedule-{{ $row->id }}" class="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-x-4 gap-y-1 py-4 md:grid-cols-[13rem_minmax(0,1fr)_12rem_auto]" data-test="schedule-row-{{ $row->id }}">
                                <time datetime="{{ $row->send_at->toIso8601String() }}" class="col-span-2 text-base text-text-primary md:col-span-1 md:pt-2.5">{{ $this->longDate($row->send_at) }}</time>
                                <div class="min-w-0 md:pt-2.5">
                                    <p class="text-base font-bold text-text-primary">{{ $row->message->type->label() }}</p>
                                    <p class="text-sm text-text-secondary">{{ $row->audience->label() }}</p>
                                </div>
                                <p class="col-start-1 text-sm md:col-start-auto md:pt-3" data-test="would-go-{{ $row->id }}">
                                    @if ($row->dm_run_id)
                                        <span class="text-text-secondary">{{ __('Gestartet, steht im Verlauf') }}</span>
                                    @else
                                        <span class="text-text-primary">{{ trans_choice('würde heute an :count gehen|würde heute an :count gehen', $row->recipientCountToday(), ['count' => $row->recipientCountToday()]) }}</span>
                                    @endif
                                </p>
                                <div class="col-start-2 row-span-2 row-start-2 self-center md:col-start-auto md:row-span-1 md:row-start-auto">
                                    @unless ($row->dm_run_id)
                                        <flux:button wire:click="removeSchedule({{ $row->id }})" wire:confirm="{{ __('„:type“ aus dem Zeitplan entfernen?', ['type' => $row->message->type->label()]) }}"
                                                     variant="ghost" icon="trash" class="min-h-11 min-w-11"
                                                     :aria-label="__('„:type“ am :when entfernen', ['type' => $row->message->type->label(), 'when' => $this->longDate($row->send_at)])"
                                                     data-test="remove-schedule-{{ $row->id }}" />
                                    @endunless
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>

        @if ($election)
            <section aria-labelledby="add-schedule-heading" class="max-w-3xl" data-test="add-schedule">
                <h2 id="add-schedule-heading" class="text-xl font-bold text-text-primary">{{ __('Nachricht einplanen') }}</h2>
                <p class="mt-2 text-base text-text-secondary">{{ __('Mit dem Standardtext der Nachricht. Zeiten gelten in deutscher Zeit.') }}</p>
                <form wire:submit="addSchedule" class="mt-6 space-y-6">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:select wire:model.live="newType" :label="__('Nachricht')" data-test="new-type">
                            @foreach ($this->scheduleTypes() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="newAudience" :label="__('Empfänger')" data-test="new-audience">
                            @foreach (\App\Enums\DmAudience::cases() as $case)
                                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input type="date" wire:model="newDate" :label="__('Tag')" data-test="new-date" />
                        <flux:input type="time" wire:model="newTime" :label="__('Uhrzeit')" data-test="new-time" />
                        @foreach ($newTypeCase->messageVariables() as $variable)
                            <flux:field wire:key="new-variable-{{ $newTypeCase->value }}-{{ $variable }}" @class(['sm:col-span-2' => $variable === 'link'])>
                                <flux:label>{{ $this->variableLabel($variable) }} <span class="ml-1 font-normal text-text-tertiary">{{ '{'.$variable.'}' }}</span></flux:label>
                                <flux:input wire:model="newVariables.{{ $variable }}" :placeholder="$this->variableHint($variable)" data-test="new-variable-{{ $variable }}" />
                                <flux:error name="newVariables.{{ $variable }}" />
                            </flux:field>
                        @endforeach
                    </div>
                    <flux:error name="newType" />
                    <details class="group max-w-[65ch]">
                        <summary class="inline-flex min-h-11 cursor-pointer items-center gap-2 text-base text-text-secondary hover:text-text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                            <flux:icon.chevron-right variant="micro" class="size-4 transition-transform group-open:rotate-90 motion-reduce:transition-none" aria-hidden="true" />
                            {{ __('Standardtext ansehen') }}
                        </summary>
                        <div class="mt-2 rounded-lg border border-border-default bg-bg-page px-4 py-3 text-base leading-7 break-words whitespace-pre-line text-text-primary">{{ $newTypeCase->defaultText() }}</div>
                    </details>
                    <flux:button type="submit" variant="primary" icon="calendar" class="min-h-11 w-full sm:w-auto" data-test="add-schedule-submit">{{ __('Einplanen') }}</flux:button>
                </form>
            </section>
        @endif
    @endif
</div>
@endif
</div>
