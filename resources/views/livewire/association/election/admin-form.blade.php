<?php

use App\Enums\ElectionCountingRule;
use App\Enums\ElectionFeeRule;
use App\Exceptions\ElectionActionRefusedException;
use App\Exceptions\ElectionRoundStateException;
use App\Livewire\Traits\WithViewer;
use App\Models\Election;
use App\Models\ElectionRound;
use App\Services\Election\ElectionSetup;
use App\Support\DisplayTime;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The settings of an election and its rounds on the board's election page:
 * a new draft when no election is given, else the selected election.
 *
 * A child of `association.election.admin`, and callable on its own like
 * every Livewire component: each public method asks the board guard itself.
 * It tells the page `election-created` after a new draft is stored and
 * `election-changed` after an edit, so the run sheet follows.
 */
new class extends Component
{
    use WithViewer;

    private const MAX_ROUNDS = 6;

    /** The edited election; null for a new draft. */
    #[Locked]
    public ?int $electionId = null;

    /**
     * @var array{name: string, meeting_date: string, meeting_time: string, call_url: string, fee_rule: string, fee_year: int|string|null, honorary_without_fee: bool, is_test: bool, rounds: list<array{id: int|null, title: string, seats: int|string, counting_rule: string}>}|array{}
     */
    public array $form = [];

    public function mount(?int $electionId = null): void
    {
        $this->boardActor();

        $this->electionId = $electionId;
        $this->form = $electionId === null ? $this->blankForm() : $this->formOf($this->requireElection());
    }

    public function addRound(): void
    {
        $this->boardActor();

        if (count($this->form['rounds'] ?? []) >= self::MAX_ROUNDS) {
            return;
        }

        $this->form['rounds'][] = ['id' => null, 'title' => '', 'seats' => 1, 'counting_rule' => ElectionCountingRule::SingleMajority->value];
    }

    public function removeRound(int $index): void
    {
        $this->boardActor();

        $rounds = $this->form['rounds'] ?? [];
        unset($rounds[$index]);
        $this->form['rounds'] = array_values($rounds);
    }

    public function save(ElectionSetup $setup): void
    {
        $actor = $this->boardActor();

        $data = $this->validate($this->formRules(), $this->formMessages())['form'];

        foreach ($data['rounds'] as $index => $round) {
            if ($round['counting_rule'] === ElectionCountingRule::SingleMajority->value && (int) $round['seats'] !== 1) {
                $this->addError("form.rounds.{$index}.seats", __('Eine Einzelwahl vergibt genau einen Sitz. Für mehrere Sitze passt die Bestätigungs- oder Mehrfachwahl.'));

                return;
            }
        }

        $feeRule = ElectionFeeRule::from($data['fee_rule']);
        $attributes = [
            'name' => $data['name'],
            'meeting_at' => $this->meetingAtOf($data),
            'call_url' => $data['call_url'] ?? null,
            'fee_rule' => $feeRule,
            'fee_year' => $feeRule->needsFeeYear() ? (int) $data['fee_year'] : null,
            'honorary_without_fee' => (bool) $data['honorary_without_fee'],
            'is_test' => (bool) $data['is_test'],
        ];
        $rounds = array_map(fn (array $round): array => [
            'id' => filled($round['id'] ?? null) ? (int) $round['id'] : null,
            'title' => $round['title'],
            'seats' => (int) $round['seats'],
            'counting_rule' => $round['counting_rule'],
        ], array_values($data['rounds']));

        try {
            if ($this->electionId === null) {
                $election = $setup->create($actor, $attributes, $rounds);
                $this->dispatch('election-created', electionId: (int) $election->id);
                Flux::toast(__('Entwurf „:name“ gespeichert.', ['name' => $election->name]), variant: 'success');

                return;
            }

            $election = $setup->update($this->requireElection(), $actor, $attributes, $rounds);
        } catch (ElectionRoundStateException) {
            $this->addError('form', __('Ein Wahlgang dieser Wahl ist schon geöffnet. Danach lässt sich die Wahl nicht mehr ändern.'));

            return;
        } catch (ElectionActionRefusedException) {
            $this->addError('form.rounds', __('Ein Wahlgang mit Kandidaturen lässt sich nicht entfernen. Lehnt sie vorher ab oder zieht sie zurück.'));

            return;
        }

        $this->saved($election);
        Flux::toast(__('Änderungen an „:name“ gespeichert.', ['name' => $election->name]), variant: 'success');
    }

    /**
     * Only the meeting time and the call link — the one change allowed after
     * a round has been opened.
     */
    public function saveMeeting(ElectionSetup $setup): void
    {
        $actor = $this->boardActor();

        $rules = array_intersect_key($this->formRules(), array_flip(['form.meeting_date', 'form.meeting_time', 'form.call_url']));
        $data = $this->validate($rules, $this->formMessages())['form'];

        $election = $setup->updateMeeting($this->requireElection(), $actor, $this->meetingAtOf($data), $data['call_url'] ?? null);

        $this->saved($election);
        Flux::toast(__('Termin und Link von „:name“ gespeichert.', ['name' => $election->name]), variant: 'success');
    }

    /**
     * The edited election with its rounds and their candidacies; null for a
     * new draft.
     */
    #[Computed]
    public function election(): ?Election
    {
        $this->boardActor();

        return $this->electionId === null ? null : Election::query()->with('rounds.candidates')->find($this->electionId);
    }

    protected function requireElection(): Election
    {
        return Election::query()->findOrFail($this->electionId ?? abort(404));
    }

    protected function saved(Election $election): void
    {
        unset($this->election);
        $this->form = $this->formOf($election->fresh());
        $this->dispatch('election-changed');
    }

    /**
     * The meeting moment a form names, read in German time and stored in UTC.
     *
     * @param  array<string, mixed>  $data
     */
    protected function meetingAtOf(array $data): ?CarbonInterface
    {
        if (blank($data['meeting_date'] ?? null)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i', $data['meeting_date'].' '.(($data['meeting_time'] ?? '') ?: '19:00'), DisplayTime::zone())
            ->setTimezone(config('app.timezone'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function blankForm(): array
    {
        return [
            'name' => '',
            'meeting_date' => '',
            'meeting_time' => '19:00',
            'call_url' => '',
            'fee_rule' => ElectionFeeRule::Year->value,
            'fee_year' => (int) now()->year,
            'honorary_without_fee' => false,
            'is_test' => false,
            'rounds' => [
                ['id' => null, 'title' => __('Präsidium'), 'seats' => 1, 'counting_rule' => ElectionCountingRule::SingleMajority->value],
                ['id' => null, 'title' => __('Vorstand'), 'seats' => 4, 'counting_rule' => ElectionCountingRule::YesNoPerCandidate->value],
                ['id' => null, 'title' => __('Revisionsstelle'), 'seats' => 1, 'counting_rule' => ElectionCountingRule::YesNoPerCandidate->value],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formOf(Election $election): array
    {
        $election->loadMissing('rounds');
        $meeting = $election->meeting_at === null ? null : DisplayTime::of($election->meeting_at);

        return [
            'name' => $election->name,
            'meeting_date' => $meeting?->format('Y-m-d') ?? '',
            'meeting_time' => $meeting?->format('H:i') ?? '19:00',
            'call_url' => (string) $election->call_url,
            'fee_rule' => $election->fee_rule->value,
            'fee_year' => $election->fee_year ?? (int) now()->year,
            'honorary_without_fee' => (bool) $election->honorary_without_fee,
            'is_test' => (bool) $election->is_test,
            'rounds' => $election->rounds->whereNull('parent_round_id')->values()->map(fn (ElectionRound $round): array => [
                'id' => (int) $round->id,
                'title' => $round->title,
                'seats' => (int) $round->seats,
                'counting_rule' => $round->counting_rule->value,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formRules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:120'],
            'form.meeting_date' => ['nullable', 'date_format:Y-m-d'],
            'form.meeting_time' => ['nullable', 'date_format:H:i'],
            'form.call_url' => ['nullable', 'url:https,http', 'max:2048'],
            'form.fee_rule' => ['required', Rule::enum(ElectionFeeRule::class)],
            'form.fee_year' => [Rule::requiredIf(fn (): bool => ($this->form['fee_rule'] ?? '') !== ElectionFeeRule::LastTwelveMonths->value), 'nullable', 'integer', 'between:2020,2100'],
            'form.honorary_without_fee' => ['boolean'],
            'form.is_test' => ['boolean'],
            'form.rounds' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROUNDS],
            'form.rounds.*.id' => ['nullable', 'integer'],
            'form.rounds.*.title' => ['required', 'string', 'max:80'],
            'form.rounds.*.seats' => ['required', 'integer', 'between:1,7'],
            'form.rounds.*.counting_rule' => ['required', Rule::enum(ElectionCountingRule::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function formMessages(): array
    {
        return [
            'form.name.required' => __('Gib der Wahl einen Namen, etwa „Vorstandswahl 2027“.'),
            'form.name.max' => __('Der Name darf höchstens 120 Zeichen lang sein.'),
            'form.meeting_date.date_format' => __('Das Datum ist nicht lesbar. Wähle es im Kalender.'),
            'form.meeting_time.date_format' => __('Die Uhrzeit ist nicht lesbar, etwa 19:00.'),
            'form.call_url.*' => __('Der Link zum Video-Call muss eine vollständige Adresse sein, die mit https:// beginnt.'),
            'form.fee_rule.*' => __('Wähle eine Beitragsregel.'),
            'form.fee_year.required' => __('Diese Beitragsregel braucht ein Beitragsjahr.'),
            'form.fee_year.*' => __('Das Beitragsjahr ist eine Jahreszahl zwischen 2020 und 2100.'),
            'form.rounds.required' => __('Lege mindestens einen Wahlgang an.'),
            'form.rounds.min' => __('Lege mindestens einen Wahlgang an.'),
            'form.rounds.max' => __('Eine Wahl hat höchstens :max Wahlgänge.', ['max' => self::MAX_ROUNDS]),
            'form.rounds.*.title.required' => __('Gib dem Wahlgang einen Titel, etwa „Präsidium“.'),
            'form.rounds.*.title.max' => __('Der Titel darf höchstens 80 Zeichen lang sein.'),
            'form.rounds.*.seats.*' => __('Ein Wahlgang vergibt 1 bis 7 Sitze.'),
            'form.rounds.*.counting_rule.*' => __('Wähle eine Zählregel.'),
        ];
    }
};
?>

@php
    $isNew = $electionId === null;
    $editedElection = $this->election;
    // Once a round is opened only the meeting time and the call link may change.
    $locked = $editedElection !== null && $editedElection->rounds->whereNotNull('opened_at')->isNotEmpty();
    $feeYear = is_numeric($form['fee_year'] ?? null) ? (int) $form['fee_year'] : null;
    $selectedRule = \App\Enums\ElectionFeeRule::tryFrom($form['fee_rule'] ?? '') ?? \App\Enums\ElectionFeeRule::Year;
    $candidatesPerRound = $editedElection?->rounds->mapWithKeys(fn ($round) => [$round->id => $round->candidates->count()])->all() ?? [];
@endphp

<form wire:submit="save" class="relative max-w-3xl space-y-10 [&_input]:min-h-11 [&_select]:min-h-11" data-test="election-form">
    @if ($locked)
        <flux:callout icon="lock-closed" variant="secondary">
            <flux:callout.text>{{ __('Ein Wahlgang dieser Wahl ist geöffnet. Name, Beitragsregel und Wahlgänge sind ab jetzt fest. Termin und Link zum Video-Call lassen sich weiter ändern, falls der Call umzieht.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @error('form')
        <flux:callout icon="exclamation-triangle" variant="danger" role="alert">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    <fieldset class="space-y-6" @disabled($locked)>
        <legend class="sr-only">{{ __('Wahl') }}</legend>

        <flux:input wire:model="form.name" :label="__('Name der Wahl')" placeholder="{{ __('Vorstandswahl 2027') }}" maxlength="120" data-test="form-name" />
    </fieldset>

    <fieldset class="space-y-6" data-test="form-meeting">
        <legend class="sr-only">{{ __('Sitzung') }}</legend>

        <div>
            <div class="grid gap-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,9rem)]">
                <flux:input type="date" wire:model="form.meeting_date" :label="__('Sitzung am')" data-test="form-meeting-date" />
                <flux:input type="time" wire:model="form.meeting_time" :label="__('Beginn')" data-test="form-meeting-time" />
            </div>
            <p class="mt-2 text-sm text-text-secondary">{{ __('Die Sitzung findet im Video-Call statt. Alle Wahlgänge laufen live in dieser Sitzung.') }}</p>
        </div>

        <flux:input type="url" wire:model="form.call_url" :label="__('Link zum Video-Call')" placeholder="https://"
                    :description="__('Mitglieder sehen den Link am Sitzungstag auf ihrer Wahlseite. Ihr könnt ihn auch später eintragen.')"
                    maxlength="2048" inputmode="url" autocomplete="off" data-test="form-call-url" />

        @if ($locked)
            <flux:button wire:click="saveMeeting" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="save-meeting">{{ __('Termin und Link speichern') }}</flux:button>
        @endif
    </fieldset>

    <fieldset class="space-y-4" @disabled($locked)>
        <legend class="text-lg font-semibold text-text-primary">{{ __('Wer wählen darf') }}</legend>
        <p class="text-base text-text-secondary">{{ __('Aktiv- und Ehrenmitglieder, die den Beitrag nach dieser Regel bezahlt haben. Passivmitglieder wählen nie.') }}</p>

        <flux:radio.group wire:model.live="form.fee_rule" variant="cards" class="flex-col" :label="__('Beitragsregel')" data-test="form-fee-rule">
            @foreach (\App\Enums\ElectionFeeRule::cases() as $rule)
                <flux:radio value="{{ $rule->value }}" :label="$rule->label($feeYear)" :description="$rule->hint($feeYear)" />
            @endforeach
        </flux:radio.group>
        <flux:error name="form.fee_rule" />

        @if ($selectedRule->needsFeeYear())
            <div class="max-w-48">
                <flux:input type="number" wire:model.live.debounce.400ms="form.fee_year" :label="__('Beitragsjahr')" min="2020" max="2100" data-test="form-fee-year" />
            </div>
        @endif

        <flux:checkbox wire:model="form.honorary_without_fee" :label="__('Ehrenmitglieder wählen auch ohne Beitrag')" class="min-h-11" data-test="form-honorary" />
    </fieldset>

    <fieldset class="space-y-4">
        <legend class="text-lg font-semibold text-text-primary">{{ __('Probewahl') }}</legend>
        @if ($isNew)
            {{-- The 32×20 track gets an invisible 62×50 hit area (WCAG 2.5.8, Apple 44 pt). --}}
            <div class="[&_ui-switch]:relative [&_ui-switch]:after:absolute [&_ui-switch]:after:-inset-x-3 [&_ui-switch]:after:-inset-y-4">
                <flux:switch wire:model="form.is_test" :label="__('Probewahl — nur für den Vorstand sichtbar')"
                             :description="__('Zum Üben mit dem Vorstand. Lässt sich nach dem Anlegen nicht mehr ändern.')" data-test="form-is-test" />
            </div>
        @else
            <p class="text-base text-text-secondary">
                {{ $form['is_test'] ? __('Ja. Diese Wahl sieht nur der Vorstand. Das ist beim Anlegen festgelegt.') : __('Nein. Das ist beim Anlegen festgelegt.') }}
            </p>
        @endif
    </fieldset>

    <fieldset class="space-y-4" @disabled($locked)>
        <legend class="text-lg font-semibold text-text-primary">{{ __('Wahlgänge') }}</legend>
        <p class="text-base text-text-secondary">{{ __('In dieser Reihenfolge wird in der Sitzung gewählt. Wer in einem Wahlgang gewählt ist, steht in den folgenden nicht mehr zur Wahl.') }}</p>

        @error('form.rounds')
            <p class="text-sm text-danger" role="alert">{{ $message }}</p>
        @enderror

        <ol class="space-y-4" data-test="form-rounds">
            @foreach ($form['rounds'] ?? [] as $index => $round)
                @php
                    $roundCandidates = filled($round['id'] ?? null) ? ($candidatesPerRound[(int) $round['id']] ?? 0) : 0;
                @endphp
                <li wire:key="round-{{ $round['id'] ?? 'new' }}-{{ $index }}" class="relative rounded-lg border border-border-default bg-bg-page p-4 sm:p-5" data-test="form-round">
                    <div class="flex items-baseline justify-between gap-4">
                        <p class="text-base font-bold text-text-primary">{{ __('Wahlgang :number', ['number' => $index + 1]) }}</p>
                        <p class="text-sm text-text-tertiary">{{ __('live in der Sitzung') }}</p>
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_7rem]">
                        <flux:input wire:model="form.rounds.{{ $index }}.title" :label="__('Titel')" maxlength="80" />
                        <flux:input type="number" wire:model="form.rounds.{{ $index }}.seats" :label="__('Sitze')" min="1" max="7" />
                    </div>
                    <div class="mt-4">
                        <flux:select wire:model.live="form.rounds.{{ $index }}.counting_rule" :label="__('Zählregel')">
                            @foreach (\App\Enums\ElectionCountingRule::cases() as $rule)
                                <flux:select.option value="{{ $rule->value }}">{{ $rule->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <p class="mt-2 text-sm text-text-secondary">{{ \App\Enums\ElectionCountingRule::tryFrom($round['counting_rule'] ?? '')?->hint() }}</p>
                    </div>
                    @unless ($locked)
                        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                            @if ($roundCandidates > 0)
                                <p class="text-sm text-text-tertiary">{{ trans_choice('{1} Hat eine Kandidatur und bleibt deshalb.|[2,*] Hat :count Kandidaturen und bleibt deshalb.', $roundCandidates, ['count' => $roundCandidates]) }}</p>
                            @elseif (count($form['rounds']) > 1)
                                <flux:button wire:click="removeRound({{ $index }})" variant="ghost" icon="trash" class="min-h-11" data-test="remove-round">{{ __('Wahlgang entfernen') }}</flux:button>
                            @endif
                        </div>
                    @endunless
                </li>
            @endforeach
        </ol>

        @unless ($locked)
            @if (count($form['rounds'] ?? []) < 6)
                <flux:button wire:click="addRound" icon="plus" variant="ghost" class="min-h-11" data-test="add-round">{{ __('Wahlgang hinzufügen') }}</flux:button>
            @endif
        @endunless
    </fieldset>

    @unless ($locked)
        <div class="flex flex-col gap-3 border-t border-border-default pt-6 sm:flex-row sm:flex-wrap sm:items-center">
            <flux:button type="submit" :variant="$isNew ? 'primary' : 'outline'" class="min-h-11 w-full sm:w-auto" data-test="save-election">
                {{ $isNew ? __('Entwurf speichern') : __('Änderungen speichern') }}
            </flux:button>
            @if ($isNew)
                <flux:button wire:click="$parent.cancelCreate" variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Abbrechen') }}</flux:button>
            @elseif (! $form['is_test'] && $editedElection?->announced_at === null)
                <flux:modal.trigger name="delete-election">
                    <flux:button variant="ghost" icon="trash" class="min-h-11 w-full text-danger! sm:ml-auto sm:w-auto" data-test="delete-election">{{ __('Wahl löschen') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    @endunless

    @if (! $isNew && $form['is_test'])
        <div class="border-t border-border-default pt-6" data-test="purge-zone">
            <p class="text-base text-text-secondary">{{ __('Nach der Probe löscht ihr die Probewahl mit allen Stimmen, Prüfsummen und Protokollzeilen.') }}</p>
            <flux:modal.trigger name="purge-election">
                <flux:button variant="ghost" icon="trash" class="mt-3 min-h-11 w-full text-danger! sm:w-auto" data-test="purge-election">{{ __('Probewahl löschen') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @endif
</form>
