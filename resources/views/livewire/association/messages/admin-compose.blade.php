@php
    $composeType = \App\Enums\DmMessageType::tryFrom($type) ?? \App\Enums\DmMessageType::Individual;
    $composeAudience = \App\Enums\DmAudience::tryFrom($audience) ?? \App\Enums\DmAudience::Members;
    $problems = $this->draftProblems;
    $hasProblems = $problems['text'] !== [] || $problems['variables'] !== [] || $problems['general'] !== [];
    $needsElection = $composeAudience->needsElection();
    $showElection = $this->elections->isNotEmpty() && ($needsElection || $composeType !== \App\Enums\DmMessageType::Individual);
    $count = $this->audienceCount;
    $current = $this->dryRunIsCurrent();
    $sendLabel = trans_choice('An ein Mitglied senden|An :count Mitglieder senden', $dryRun['count'] ?? 0, ['count' => $dryRun['count'] ?? 0]);
    $stepNumber = 'flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-border-default text-sm font-bold text-text-secondary';
@endphp

{{-- One page in the order the decision is made: what, to whom, which words,
     how they read, how many, send. A real sequence, so a numbered list. --}}
<form wire:submit="runDryRun" class="max-w-3xl [&_input]:min-h-11 [&_select]:min-h-11">
    @if ($problems['general'] !== [])
        <flux:callout variant="danger" icon="exclamation-triangle" class="mb-8">
            @foreach ($problems['general'] as $problem)
                <flux:callout.text>{{ $problem }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    <ol class="space-y-12">
        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">1</span>
            <div>
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Art der Nachricht') }}</h2>
                <div class="mt-4">
                    <flux:select wire:model.live="type" :label="__('Nachricht')" data-test="compose-type">
                        @foreach (\App\Enums\DmMessageType::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">2</span>
            <div>
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Empfänger') }}</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="audience" :label="__('Wer bekommt sie')" data-test="compose-audience">
                        @foreach (\App\Enums\DmAudience::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($showElection)
                        <flux:select wire:model.live="electionId" :label="__('Wahl')" data-test="compose-election">
                            @foreach ($this->elections as $item)
                                <flux:select.option value="{{ $item->id }}">{{ $item->is_test ? __(':name (Probewahl)', ['name' => $item->name]) : $item->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @endif
                </div>
                {{-- The sentence is the answer to "to whom": counted now, in words. --}}
                <p class="mt-6 text-xl font-semibold leading-snug text-text-primary break-words" aria-live="polite" data-test="audience-sentence">
                    @if ($needsElection && $this->composeElection === null)
                        {{ __('Für diese Empfänger braucht es eine Wahl. Lege sie unter „Wahl leiten“ an.') }}
                    @else
                        {{ $this->audienceSentence }}
                    @endif
                </p>
                @if ($count === 0 && ! ($needsElection && $this->composeElection === null))
                    <p class="mt-2 text-base text-text-secondary">{{ __('Auf diese Auswahl passt heute niemand.') }}</p>
                @endif
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">3</span>
            <div class="min-w-0">
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Text') }}</h2>
                @if ($composeType->messageVariables() !== [])
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach ($composeType->messageVariables() as $variable)
                            <flux:field wire:key="variable-{{ $composeType->value }}-{{ $variable }}" @class(['sm:col-span-2' => $variable === 'link'])>
                                <flux:label>{{ $this->variableLabel($variable) }} <span class="ml-1 font-normal text-text-tertiary">{{ '{'.$variable.'}' }}</span></flux:label>
                                <flux:input wire:model.live.debounce.400ms="variables.{{ $variable }}" :placeholder="$this->variableHint($variable)" data-test="variable-{{ $variable }}" />
                                @isset ($problems['variables'][$variable])
                                    <p class="mt-1 text-sm text-text-secondary" data-test="variable-problem-{{ $variable }}">{{ $problems['variables'][$variable] }}</p>
                                @endisset
                            </flux:field>
                        @endforeach
                    </div>
                @endif

                <flux:field class="mt-6">
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <flux:label for="compose-text">{{ __('Nachricht') }}</flux:label>
                        @if ($text !== $composeType->defaultText())
                            <flux:button type="button" wire:click="restoreDefaultText" variant="ghost" size="sm" class="min-h-11" data-test="restore-text">{{ __('Standardtext wiederherstellen') }}</flux:button>
                        @endif
                    </div>
                    <flux:textarea id="compose-text" wire:model.live.debounce.400ms="text" rows="9" class="leading-7" data-test="compose-text"
                                   :aria-invalid="$problems['text'] !== [] ? 'true' : null" aria-describedby="compose-text-help" />
                    @foreach ($problems['text'] as $problem)
                        <p class="mt-2 flex items-start gap-2 text-sm text-danger" data-test="text-problem">
                            <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ $problem }}
                        </p>
                    @endforeach
                    <p id="compose-text-help" class="mt-2 text-sm text-text-secondary">
                        {{ __('Nur Text, keine Formatierung: Nostr-Clients zeigen ihn, wie er ist. Platzhalter in geschweiften Klammern werden ersetzt:') }}
                        @foreach ($composeType->variables() as $variable)
                            <span class="whitespace-nowrap text-text-primary">{{ '{'.$variable.'}' }}</span>@if (! $loop->last), @endif
                        @endforeach
                        @if (in_array('name', $composeType->variables(), true))
                            {{ __('({name} ist der Name des Empfängers).') }}
                        @endif
                        {{ __('Der Hinweis, wohin Fragen gehen, wird unten angehängt.') }}
                    </p>
                </flux:field>
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">4</span>
            <div class="min-w-0">
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Vorschau') }}</h2>
                @if ($hasProblems)
                    <p class="mt-2 text-base text-text-secondary">{{ __('Die Vorschau erscheint, sobald der Text vollständig ist.') }}</p>
                @elseif ($this->previews === [])
                    <p class="mt-2 text-base text-text-secondary">{{ __('Ohne Empfänger gibt es keine Vorschau.') }}</p>
                @else
                    <p class="mt-2 text-base text-text-secondary">{{ trans_choice('So liest es der erste Empfänger.|So lesen es die ersten :count Empfänger.', count($this->previews), ['count' => count($this->previews)]) }}</p>
                    <div class="mt-4 space-y-6" data-test="previews">
                        @foreach ($this->previews as $preview)
                            <figure wire:key="preview-{{ $loop->index }}" class="max-w-[65ch]">
                                <figcaption class="text-sm text-text-tertiary">{{ __('An :name, von EINUNDZWANZIG', ['name' => $preview['name']]) }}</figcaption>
                                <div class="mt-2 rounded-lg rounded-tl-none border border-border-default bg-bg-elevated px-4 py-3 text-base leading-7 break-words whitespace-pre-line text-text-primary">{{ $preview['text'] }}</div>
                            </figure>
                        @endforeach
                    </div>
                @endif
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">5</span>
            <div class="min-w-0">
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Probelauf') }}</h2>
                <p class="mt-2 text-base text-text-secondary">{{ __('Zählt die Empfänger und zeigt, wer keinen Nostr-Posteingang hat. Gesendet wird dabei nichts.') }}</p>
                <flux:button type="submit" variant="outline" icon="magnifying-glass" class="mt-4 min-h-11 w-full sm:w-auto" :disabled="$hasProblems || $count === 0" data-test="dry-run">
                    {{ __('Probelauf starten') }}
                </flux:button>

                @if ($dryRun !== null)
                    <div @class(['mt-6 rounded-r-lg border-l-4 bg-bg-elevated p-4 sm:p-6', 'border-orange-500' => $current, 'border-border-default' => ! $current]) aria-live="polite" data-test="dry-run-result">
                        @if (! $current)
                            <p class="text-base text-text-secondary">{{ __('Seit dem Probelauf hat sich etwas geändert. Starte ihn neu, dann siehst du die Zahl, die gesendet wird.') }}</p>
                        @else
                            <p class="text-lg text-text-primary">
                                <span class="font-bold">{{ trans_choice('Ein Empfänger.|:count Empfänger.', $dryRun['count'], ['count' => $dryRun['count']]) }}</span>
                                @if ($dryRun['unreachable_count'] === 0)
                                    {{ __('Alle haben einen Nostr-Posteingang.') }}
                                @else
                                    {{ trans_choice('Einer davon hat keinen Nostr-Posteingang und liest die Nachricht nur im Portal.|:count davon haben keinen Nostr-Posteingang und lesen die Nachricht nur im Portal.', $dryRun['unreachable_count'], ['count' => $dryRun['unreachable_count']]) }}
                                @endif
                            </p>
                            @if ($dryRun['unreachable'] !== [])
                                <p class="mt-2 text-sm text-text-secondary break-words" data-test="unreachable">
                                    {{ implode(', ', $dryRun['unreachable']) }}@if ($dryRun['unreachable_count'] > count($dryRun['unreachable'])){{ __(' und :count weitere', ['count' => $dryRun['unreachable_count'] - count($dryRun['unreachable'])]) }}@endif
                                </p>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        </li>

        <li class="grid grid-cols-[2rem_minmax(0,1fr)] gap-x-4">
            <span class="{{ $stepNumber }}" aria-hidden="true">6</span>
            <div class="min-w-0">
                <h2 class="pt-0.5 text-xl font-bold text-text-primary">{{ __('Senden') }}</h2>
                <p class="mt-2 text-base text-text-secondary">{{ __('Ein Test geht nur an dich. Danach sendest du an alle, die der Probelauf gezählt hat.') }}</p>
                <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <flux:button type="button" wire:click="sendTestToSelf" variant="outline" icon="user" class="min-h-11 w-full sm:w-auto" :disabled="$hasProblems" data-test="send-test">
                        {{ __('Test an mich senden') }}
                    </flux:button>
                    @if ($current && $dryRun['count'] > 0)
                        <flux:modal.trigger name="confirm-send">
                            <flux:button type="button" variant="primary" icon="paper-airplane" class="min-h-11 w-full sm:w-auto" data-test="open-confirm">{{ $sendLabel }}</flux:button>
                        </flux:modal.trigger>
                    @else
                        <flux:button type="button" variant="primary" icon="paper-airplane" class="min-h-11 w-full sm:w-auto" disabled data-test="open-confirm-disabled">{{ __('Erst Probelauf, dann senden') }}</flux:button>
                    @endif
                </div>
            </div>
        </li>
    </ol>
</form>

@if ($current && $dryRun['count'] > 0)
    <flux:modal name="confirm-send" :closable="false" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ trans_choice('„:type“ an ein Mitglied senden?|„:type“ an :count Mitglieder senden?', $dryRun['count'], ['count' => $dryRun['count'], 'type' => $composeType->label()]) }}</flux:heading>
                <flux:text class="mt-2">{{ $this->audienceSentence }}.</flux:text>
                <flux:text class="mt-2">{{ __('Die Empfängerliste wird beim Start festgeschrieben und die Nachricht geht sofort raus. Eine Nostr-Nachricht lässt sich nicht zurückholen.') }}</flux:text>
            </div>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <flux:modal.close>
                    <flux:button variant="ghost" class="min-h-11 w-full sm:w-auto">{{ __('Abbrechen') }}</flux:button>
                </flux:modal.close>
                <flux:button wire:click="confirmSend" variant="primary" class="min-h-11 w-full sm:w-auto" data-test="confirm-send">{{ $sendLabel }}</flux:button>
            </div>
        </div>
    </flux:modal>
@endif
