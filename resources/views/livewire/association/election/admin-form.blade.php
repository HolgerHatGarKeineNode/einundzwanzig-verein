{{-- Settings of an election and its rounds. Included by admin.blade.php; $isNew and $locked come from there. --}}
@php
    $feeYear = is_numeric($form['fee_year'] ?? null) ? (int) $form['fee_year'] : null;
    $selectedRule = \App\Enums\ElectionFeeRule::tryFrom($form['fee_rule'] ?? '') ?? \App\Enums\ElectionFeeRule::Year;
    $editedElection = $isNew ? null : $this->selectedElection;
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
                <flux:radio value="{{ $rule->value }}" :label="$this->feeRuleLabel($rule, $feeYear)" :description="$this->feeRuleHint($rule, $feeYear)" />
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
                                <flux:select.option value="{{ $rule->value }}">{{ $this->countingRuleLabel($rule) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <p class="mt-2 text-sm text-text-secondary">{{ $this->countingRuleHint($round['counting_rule'] ?? '') }}</p>
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
                <flux:button wire:click="cancelCreate" variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Abbrechen') }}</flux:button>
            @elseif (! $form['is_test'])
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
