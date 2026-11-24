{{-- Electoral roll of the selected election. Included by admin.blade.php with $election. Counts and names only, never votes. --}}
@php
    $opened = $this->openedRounds;
    $announced = $election->announced_at !== null;
    $ruleLabel = $election->fee_rule->label($election->fee_year);
@endphp

<div class="relative" data-test="roll">
    @if ($opened->isEmpty())
        @php
            $rows = $this->rollPreview;
            $entitledCount = $rows->whereNotNull('reason')->count();
            $signedInCount = $rows->whereNotNull('reason')->whereNotNull('signed_in')->count();
        @endphp
        <div class="max-w-2xl space-y-2">
            <p class="text-lg text-text-primary" data-test="roll-summary">
                {{ trans_choice('{0} Noch niemand ist wahlberechtigt.|{1} :count von :total Aktiv- und Ehrenmitgliedern ist wahlberechtigt.|[2,*] :count von :total Aktiv- und Ehrenmitgliedern sind wahlberechtigt.', $entitledCount, ['count' => $entitledCount, 'total' => $rows->count()]) }}
                @if ($announced)
                    {{ trans_choice('{0} Davon hat sich seit der Ankündigung noch niemand angemeldet.|{1} Davon hat sich eine Person seit der Ankündigung angemeldet.|[2,*] Davon haben sich :count seit der Ankündigung angemeldet.', $signedInCount, ['count' => $signedInCount]) }}
                @endif
            </p>
            <p class="text-sm text-text-secondary">
                {{ __('Stand jetzt, nach der Regel „:rule“. Beim Öffnen jedes Wahlgangs wird das Verzeichnis festgeschrieben; wer danach bezahlt, kommt automatisch dazu.', ['rule' => $ruleLabel]) }}
            </p>
            @unless ($announced)
                <p class="text-sm text-text-secondary">{{ __('Ab der Ankündigung zeigt die Liste auch, wer sich seitdem mit Nostr angemeldet hat. Wer das nicht hat, braucht vor der Sitzung vielleicht Hilfe.') }}</p>
            @endunless
        </div>

        @if ($rows->isEmpty())
            <p class="mt-6 max-w-2xl rounded-lg border border-dashed border-border-default px-6 py-10 text-base text-text-secondary">{{ __('Es gibt keine Aktiv- oder Ehrenmitglieder.') }}</p>
        @else
            <ul class="mt-6 divide-y divide-border-default rounded-lg border border-border-default bg-bg-surface" data-test="roll-list">
                <li class="relative hidden gap-4 px-5 py-3 text-sm text-text-tertiary sm:grid {{ $announced ? 'sm:grid-cols-[minmax(0,1fr)_17rem_13rem]' : 'sm:grid-cols-[minmax(0,1fr)_17rem]' }}" aria-hidden="true">
                    <span>{{ __('Mitglied') }}</span>
                    <span>{{ __('Wahlberechtigt') }}</span>
                    @if ($announced)<span>{{ __('Seit Ankündigung angemeldet') }}</span>@endif
                </li>
                @foreach ($rows as $row)
                    <li wire:key="roll-preview-{{ $loop->index }}" class="relative grid gap-1 px-4 py-3 sm:gap-4 sm:px-5 {{ $announced ? 'sm:grid-cols-[minmax(0,1fr)_17rem_13rem]' : 'sm:grid-cols-[minmax(0,1fr)_17rem]' }}" data-test="roll-row">
                        <span class="min-w-0 font-bold text-text-primary break-words"><x-nostr-profile :pubkey="$row['pubkey']" :name="$row['name']" :picture="$row['picture']" /></span>
                        <span class="text-sm sm:text-base">
                            <span class="text-text-tertiary sm:sr-only">{{ __('Wahlberechtigt:') }}</span>
                            @if ($row['reason'])
                                <span class="text-text-primary">{{ __('Ja') }}</span><span class="text-text-secondary">, {{ $row['reason']->label() }}</span>
                            @else
                                <span class="text-text-secondary">{{ __('Nein') }}, {{ $election->fee_rule->missingText($election->fee_year) }}</span>
                            @endif
                        </span>
                        @if ($announced)
                            <span class="text-sm sm:text-base">
                                <span class="text-text-tertiary sm:sr-only">{{ __('Seit Ankündigung angemeldet:') }}</span>
                                @if ($row['signed_in'])
                                    <span class="text-text-primary">{{ __('Ja, :date', ['date' => $this->shortDate($row['signed_in'])]) }}</span>
                                @elseif ($row['reason'])
                                    <span class="inline-flex items-center gap-1.5 text-warning"><flux:icon.exclamation-triangle variant="micro" class="size-4" aria-hidden="true" />{{ __('Nein') }}</span>
                                @else
                                    <span class="text-text-secondary">{{ __('Nein') }}</span>
                                @endif
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @else
        @php
            $round = $this->rollRound;
            $entries = $this->rollEntries;
            $takesEnrolment = in_array($round->stateAt(now()), [\App\Enums\ElectionRoundState::Scheduled, \App\Enums\ElectionRoundState::Open], true);
        @endphp

        @if ($opened->count() > 1)
            <div class="mb-6 max-w-sm [&_select]:min-h-11">
                <flux:select wire:model.live="rollRoundId" :label="__('Verzeichnis von')" data-test="roll-round">
                    @foreach ($opened as $openedRound)
                        <flux:select.option value="{{ $openedRound->id }}">{{ $openedRound->title }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <div class="max-w-2xl space-y-2">
            <p class="text-lg text-text-primary" data-test="roll-summary">
                {{ trans_choice('{0} Das Verzeichnis von „:round“ ist leer.|{1} Im Verzeichnis von „:round“ steht eine Person.|[2,*] Im Verzeichnis von „:round“ stehen :count Personen.', $entries->count(), ['count' => $entries->count(), 'round' => $round->title]) }}
            </p>
            <p class="text-sm text-text-secondary">{{ __('Festgeschrieben beim Öffnen am :date. Niemand wird daraus entfernt.', ['date' => $this->shortDate($round->opened_at).', '.$round->opened_at->format('H:i')]) }}</p>
        </div>

        @if ($takesEnrolment)
            <form wire:submit="enrol" class="relative mt-6 max-w-2xl space-y-4 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6 [&_input]:min-h-11 [&_select]:min-h-11" data-test="enrol-form">
                <h3 class="text-lg font-semibold text-text-primary">{{ __('Mitglied von Hand nachtragen') }}</h3>
                <p class="text-base text-text-secondary">{{ __('Für einen Beitrag, den die Zahlung nicht kennt, etwa bar bezahlt. Der Grund steht im Protokoll der Wahl.') }}</p>
                <flux:select wire:model="enrolMemberId" :label="__('Mitglied')" :placeholder="__('Mitglied wählen')" data-test="enrol-member">
                    @foreach ($this->enrolCandidates as $candidate)
                        <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="enrolReason" :label="__('Grund')" maxlength="1000" placeholder="{{ __('z. B. Beitrag bar bezahlt am 3. März') }}" data-test="enrol-reason" />
                <flux:button type="submit" class="min-h-11 w-full sm:w-auto" data-test="enrol-submit">{{ __('Nachtragen') }}</flux:button>
            </form>
        @else
            <p class="mt-4 text-sm text-text-secondary">{{ __('Nachtragen von Hand geht nur, solange der Wahlgang offen ist.') }}</p>
        @endif

        <ul class="mt-6 divide-y divide-border-default rounded-lg border border-border-default bg-bg-surface" data-test="roll-list">
            <li class="relative hidden gap-4 px-5 py-3 text-sm text-text-tertiary sm:grid sm:grid-cols-[minmax(0,1fr)_17rem_13rem]" aria-hidden="true">
                <span>{{ __('Mitglied') }}</span>
                <span>{{ __('Grund') }}</span>
                <span>{{ __('Seit Ankündigung angemeldet') }}</span>
            </li>
            @foreach ($entries as $row)
                <li wire:key="roll-entry-{{ $loop->index }}" class="relative grid gap-1 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_17rem_13rem] sm:gap-4 sm:px-5" data-test="roll-row">
                    <span class="min-w-0 font-bold text-text-primary break-words"><x-nostr-profile :pubkey="$row['pubkey']" :name="$row['name']" :picture="$row['picture']" /></span>
                    <span class="text-sm sm:text-base">
                        <span class="text-text-tertiary sm:sr-only">{{ __('Grund:') }}</span>
                        <span class="text-text-primary">{{ $row['reason']->label() }}</span><span class="text-text-secondary">, {{ $row['source']->label() }}</span>
                    </span>
                    <span class="text-sm sm:text-base">
                        <span class="text-text-tertiary sm:sr-only">{{ __('Seit Ankündigung angemeldet:') }}</span>
                        @if ($row['signed_in'])
                            <span class="text-text-primary">{{ __('Ja, :date', ['date' => $this->shortDate($row['signed_in'])]) }}</span>
                        @else
                            <span class="inline-flex items-center gap-1.5 text-warning"><flux:icon.exclamation-triangle variant="micro" class="size-4" aria-hidden="true" />{{ __('Nein') }}</span>
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($this->takesEntitlement($election))
        <form wire:submit="entitle" class="relative mt-8 max-w-2xl space-y-4 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-6 [&_input]:min-h-11 [&_select]:min-h-11" data-test="entitle-form">
            <h3 class="text-lg font-semibold text-text-primary">{{ __('Wahlberechtigung von Hand eintragen') }}</h3>
            <p class="text-base text-text-secondary">{{ __('Für ein Aktiv- oder Ehrenmitglied, dessen Beitrag die Zahlung nicht kennt, etwa bar bezahlt. Danach lässt sich seine Kandidatur freigeben, und jeder Wahlgang, den ihr danach öffnet, nimmt es ins Verzeichnis auf. Der Grund steht im Protokoll der Wahl.') }}</p>
            <flux:select wire:model="entitleMemberId" :label="__('Mitglied')" :placeholder="__('Mitglied wählen')" data-test="entitle-member">
                @foreach ($this->entitleCandidates as $candidate)
                    <flux:select.option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="entitleReason" :label="__('Grund')" maxlength="1000" placeholder="{{ __('z. B. Beitrag bar bezahlt am 3. März') }}" data-test="entitle-reason" />
            <flux:button type="submit" class="min-h-11 w-full sm:w-auto" data-test="entitle-submit">{{ __('Als wahlberechtigt eintragen') }}</flux:button>
        </form>
    @endif
</div>
