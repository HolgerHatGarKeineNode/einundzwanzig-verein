{{-- The meeting: every round with its state, live turnout and result. Included by admin.blade.php with $election. Counts only, never a vote. --}}
@php
    $reports = $this->roundReports;
    $titles = $reports->mapWithKeys(fn ($report) => [$report['round']->id => $report['round']->title]);
    // Only one round takes ballots at a time: while one is open, no other is opened.
    $openReport = $reports->first(fn ($report) => $report['state'] === \App\Enums\ElectionRoundState::Open);
    // Numbering follows the agenda, as on the share view: a run-off belongs to its round, an annulled round is void and its repeat takes its number.
    $number = 0;
    // A name may end in a full stop ("Erik E."); the sentence must not add a second one.
    $sentence = fn (string $text): string => preg_replace('/(?<=\S)\.\.(?=\s|$)/u', '.', $text);
@endphp

<div class="relative" data-test="live">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <p class="max-w-2xl text-base text-text-secondary">
            {{ __('Hier steuert ihr die Wahlgänge in der Sitzung. Teilt im Call die Ansicht zum Teilen: Sie zeigt Beteiligung und Ergebnis groß, ohne Bedienelemente, und aktualisiert sich von selbst.') }}
        </p>
        <div class="flex flex-col gap-2 sm:flex-row lg:shrink-0">
            <flux:button :href="route('association.election.admin.screen', $election)" target="_blank" icon="tv" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="open-screen">
                {{ __('Ansicht zum Teilen') }}
            </flux:button>
            <flux:button :href="route('association.election.admin.protocol', $election)" target="_blank" icon="document-text" variant="ghost" class="min-h-11 w-full sm:w-auto" data-test="open-protocol">
                {{ __('Beschlussprotokoll') }}
            </flux:button>
        </div>
    </div>

    @if ($reports->isEmpty())
        <p class="mt-8 max-w-2xl rounded-xl border border-dashed border-border-default px-5 py-6 text-base text-text-secondary">{{ __('Diese Wahl hat noch keinen Wahlgang. Legt ihn unter „Einstellungen“ an.') }}</p>
    @else
        <ol class="mt-8 border-b border-border-default" data-test="live-rounds">
            @foreach ($reports as $report)
                @php
                    $round = $report['round'];
                    $isRunOff = $round->parent_round_id !== null;
                    $isVoid = ! $isRunOff && $report['state'] === \App\Enums\ElectionRoundState::Annulled;
                    if (! $isRunOff && ! $isVoid) { $number++; }
                    $isOpen = $report['state'] === \App\Enums\ElectionRoundState::Open;
                    $result = $report['result'];
                    $isYesNo = $round->counting_rule === \App\Enums\ElectionCountingRule::YesNoPerCandidate;
                @endphp
                <li wire:key="live-round-{{ $round->id }}" @class([
                        'relative grid grid-cols-[2rem_minmax(0,1fr)] gap-x-3 border-t border-border-default py-6 sm:grid-cols-[2.5rem_minmax(0,1fr)]',
                        'sm:pl-8' => $isRunOff,
                    ]) data-test="live-round-{{ $round->id }}" data-state="{{ $report['over_by_clock'] ? 'over' : $report['state']->value }}">
                    {{-- The "now" marker: the open round carries the same orange rule as the next step. --}}
                    @if ($isOpen)
                        <span class="absolute -left-3 top-6 bottom-6 w-1 bg-orange-500 sm:-left-4" aria-hidden="true"></span>
                    @endif
                    <span class="pt-0.5 text-lg font-bold text-text-tertiary tabular-nums" aria-hidden="true" data-test="live-number">{{ $isRunOff || $isVoid ? '' : $number }}</span>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1">
                            <h3 class="min-w-0 text-xl font-bold leading-tight text-text-primary break-words">
                                <span class="sr-only">{{ match (true) { $isRunOff => __('Stichwahl'), $isVoid => __('Annullierter Wahlgang, zählt nicht mit'), default => __('Wahlgang :number', ['number' => $number]) } }}:</span>{{ $round->title }}
                            </h3>
                            <p class="inline-flex items-center gap-1.5 text-base" data-test="live-state">
                                @if ($isOpen)
                                    <span class="block size-2.5 rounded-full bg-orange-500" aria-hidden="true"></span>
                                    <span class="font-bold text-text-primary">{{ __('Offen seit :time Uhr', ['time' => $this->clock($round->opened_at)]) }}</span>
                                @elseif ($report['over_by_clock'])
                                    <flux:icon.clock variant="mini" class="size-5 text-warning" aria-hidden="true" />
                                    <span class="text-warning">{{ __('Zeit abgelaufen') }}</span>
                                @elseif ($report['state'] === \App\Enums\ElectionRoundState::Annulled)
                                    <flux:icon.no-symbol variant="mini" class="size-5 text-danger" aria-hidden="true" />
                                    <span class="text-danger">{{ __('Annulliert um :time Uhr', ['time' => $this->clock($round->annulled_at)]) }}</span>
                                @elseif ($report['sealed'])
                                    <flux:icon.check-circle variant="mini" class="size-5 text-success" aria-hidden="true" />
                                    <span class="text-text-primary">{{ __('Ausgezählt um :time Uhr', ['time' => $this->clock($round->closed_at)]) }}</span>
                                @else
                                    <flux:icon.pause-circle variant="mini" class="size-5 text-text-tertiary" aria-hidden="true" />
                                    <span class="text-text-secondary">{{ __('Noch nicht geöffnet') }}</span>
                                @endif
                            </p>
                        </div>
                        <p class="mt-1 text-sm text-text-secondary break-words">
                            @if ($isRunOff)
                                @if ($isYesNo)
                                    {{ trans_choice('{1} Stichwahl zu „:parent“, ein Sitz. Abgestimmt wird mit Ja oder Nein.|[2,*] Stichwahl zu „:parent“, :count Sitze. Abgestimmt wird mit Ja oder Nein.', $round->seats, ['parent' => $titles->get($round->parent_round_id, ''), 'count' => $round->seats]) }}
                                @else
                                    {{ trans_choice('{1} Stichwahl zu „:parent“, ein Sitz. Gewählt ist, wer die meisten Stimmen hat.|[2,*] Stichwahl zu „:parent“, :count Sitze. Gewählt sind, die am häufigsten genannt werden.', $round->seats, ['parent' => $titles->get($round->parent_round_id, ''), 'count' => $round->seats]) }}
                                @endif
                            @else
                                {{ $this->countingRuleLabel($round->counting_rule) }}, {{ trans_choice('{1} ein Sitz|[2,*] :count Sitze', $round->seats, ['count' => $round->seats]) }}.
                            @endif
                            @if (! $report['sealed'] && $report['candidates'] !== [])
                                {{ __('Zur Wahl: :names.', ['names' => implode(', ', $report['candidates'])]) }}
                            @endif
                        </p>

                        @if ($isOpen)
                            <div class="mt-5">
                                <livewire:association.election.turnout :round-id="$round->id" :key="'turnout-'.$round->id" />
                            </div>
                            <p class="mt-3 text-sm text-text-secondary">{{ __('Nimmt bis :time Uhr Stimmen an, wenn ihr ihn nicht vorher schließt.', ['time' => $this->clock($round->closes_at)]) }}</p>
                            <div class="mt-5 flex flex-col gap-2 sm:flex-row">
                                <flux:button wire:click="startRoundAction({{ $round->id }}, 'close')" icon="stop-circle" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="close-round-{{ $round->id }}">{{ __('Wahlgang schließen') }}</flux:button>
                                <flux:button wire:click="startRoundAction({{ $round->id }}, 'annul')" variant="ghost" class="min-h-11 w-full text-danger! sm:w-auto" data-test="annul-round-{{ $round->id }}">{{ __('Annullieren') }}</flux:button>
                            </div>
                        @elseif ($report['over_by_clock'])
                            <p class="mt-4 text-base text-text-primary">{{ trans_choice('{1} :count von :roll hat abgestimmt.|[0,*] :count von :roll haben abgestimmt.', $report['cast'], ['roll' => $report['roll']]) }} {{ __('Nach :minutes Minuten nimmt der Wahlgang keine Stimmen mehr an.', ['minutes' => \App\Services\Election\OpenRound::LIVE_MAX_MINUTES]) }}</p>
                            <div class="mt-4">
                                <flux:button wire:click="sealRound({{ $round->id }})" icon="calculator" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="seal-round-{{ $round->id }}">{{ __('Auszählen') }}</flux:button>
                            </div>
                        @elseif ($result !== null)
                            @php $electedNames = collect($result['rows'])->where('elected', true)->pluck('name'); @endphp
                            <p class="mt-4 text-lg font-bold text-text-primary break-words" data-test="live-outcome-{{ $round->id }}">
                                @switch($result['outcome'])
                                    @case(\App\Enums\ElectionOutcome::Complete)
                                        {{ $sentence(trans_choice('{1} Gewählt ist :names.|[2,*] Gewählt sind :names.', $electedNames->count(), ['names' => $electedNames->join(', ', ' und ')])) }}
                                        @break
                                    @case(\App\Enums\ElectionOutcome::Tie)
                                        <span class="inline-flex items-center gap-1.5 text-warning"><flux:icon.scale variant="mini" class="size-5" aria-hidden="true" />{{ $result['tie_kind'] === \App\Enums\ElectionTieKind::YesEqualsNo ? $sentence(\App\Support\ElectionTieText::yesEqualsNo(collect($result['rows'])->where('tied', true)->pluck('name')->all())) : trans_choice('{1} Gleichstand um einen Sitz.|[2,*] Gleichstand um :count Sitze.', $result['tied_seats'], ['count' => $result['tied_seats']]) }}</span>
                                        <span class="block text-base font-normal text-text-primary" data-test="live-tie-next-{{ $round->id }}">{{ $report['run_off_resolved'] ? \App\Support\ElectionTieText::runOffResolved() : \App\Support\ElectionTieText::pending() }}</span>
                                        @if ($electedNames->isNotEmpty())
                                            <span class="block text-base font-normal text-text-primary">{{ $sentence(trans_choice('{1} Gewählt ist schon :names.|[2,*] Gewählt sind schon :names.', $electedNames->count(), ['names' => $electedNames->join(', ', ' und ')])) }}</span>
                                        @endif
                                        @break
                                    @default
                                        <span class="inline-flex items-center gap-1.5 text-warning"><flux:icon.exclamation-triangle variant="mini" class="size-5" aria-hidden="true" />{{ $electedNames->isEmpty() ? __('Niemand hat die nötige Mehrheit.') : __('Nicht alle Sitze sind besetzt.') }}</span>
                                        @if ($electedNames->isNotEmpty())
                                            <span class="block text-base font-normal text-text-primary">{{ $sentence(trans_choice('{1} Gewählt ist :names.|[2,*] Gewählt sind :names.', $electedNames->count(), ['names' => $electedNames->join(', ', ' und ')])) }}</span>
                                        @endif
                                        {{-- No run-off follows (one candidate, or a yes/no round): say that the seat stays empty and how to vote again. --}}
                                        @if ($report['run_off'] === null && ! $report['has_run_off'] && ($freeSeats = $round->seats - $electedNames->count()) > 0)
                                            <span class="block text-base font-normal text-text-primary" data-test="live-seats-open-{{ $round->id }}">{{ trans_choice('{1} Der Sitz bleibt frei.|[2,*] :count Sitze bleiben frei.', $freeSeats, ['count' => $freeSeats]) }} {{ __('Neu abstimmen geht nur über „Wahlgang annullieren“ und danach „Wahlgang wiederholen“.') }}</span>
                                        @endif
                                @endswitch
                                @if ($result['casting_vote'] !== null)
                                    <span class="mt-1 block text-base font-normal text-text-primary" data-test="live-casting-vote-{{ $round->id }}">{{ $sentence(\App\Support\ElectionTieText::decided($result['tie_kind'], $result['casting_vote']['chair'], $result['casting_vote']['elected'], $result['casting_vote']['tied'])) }}@if ($result['casting_vote']['note']) {{ __('Anmerkung: :note', ['note' => $result['casting_vote']['note']]) }}@endif</span>
                                @endif
                            </p>

                            <ul class="mt-4 max-w-2xl divide-y divide-border-subtle border-y border-border-subtle" data-test="live-result-{{ $round->id }}">
                                @foreach ($result['rows'] as $row)
                                    <li class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-4 gap-y-1 py-3 sm:grid-cols-[minmax(0,1fr)_auto_8rem]">
                                        <span class="min-w-0 font-bold text-text-primary break-words">{{ $row['name'] }}</span>
                                        <span class="text-right tabular-nums text-text-primary">
                                            @if ($isYesNo)
                                                {{ __(':yes Ja, :no Nein', ['yes' => $row['yes'], 'no' => $row['no']]) }}
                                            @else
                                                {{ trans_choice('{0} 0 Stimmen|{1} 1 Stimme|[2,*] :count Stimmen', $row['for'], ['count' => $row['for']]) }}
                                            @endif
                                        </span>
                                        <span class="col-span-2 text-sm sm:col-span-1 sm:text-right">
                                            @if ($row['elected'])
                                                <span class="inline-flex items-center gap-1 text-success"><flux:icon.check-circle variant="micro" class="size-4" aria-hidden="true" />{{ __('gewählt') }}</span>
                                            @elseif ($row['tied'])
                                                <span class="inline-flex items-center gap-1 text-warning"><flux:icon.scale variant="micro" class="size-4" aria-hidden="true" />{{ __('Gleichstand') }}</span>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-3 text-sm text-text-secondary break-words">
                                {{ trans_choice('{1} :count von :roll hat abgestimmt,|[0,*] :count von :roll haben abgestimmt,', $report['cast'], ['roll' => $report['roll']]) }} {{ trans_choice('{0} davon keine Enthaltung (leer).|{1} davon 1 Enthaltung (leer).|[2,*] davon :count Enthaltungen (leer).', $result['blank']) }}
                                @if ($report['reason'])
                                    {{ __('Geschlossen mit dem Grund „:reason“.', ['reason' => $report['reason']]) }}
                                @endif
                            </p>
                            @if ($report['manifest'])
                                <p class="mt-1 text-sm text-text-tertiary break-all">
                                    {{ __('Prüfsumme :hash', ['hash' => $report['manifest']['hash']]) }}
                                    @unless ($report['manifest']['intact'])
                                        <span class="inline-flex items-center gap-1 text-danger"><flux:icon.exclamation-triangle variant="micro" class="size-4" aria-hidden="true" />{{ __('stimmt nicht mehr mit den Stimmen überein') }}</span>
                                    @endunless
                                </p>
                            @endif

                            <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                                {{-- A tie: the chair's casting vote is the statutory way (Art. 6); a run-off only if the assembly resolves one. Both open a dialog. --}}
                                @if ($result['outcome'] === \App\Enums\ElectionOutcome::Tie && ! $report['has_run_off'])
                                    <flux:button wire:click="startCastingVote({{ $round->id }})" icon="scale" variant="primary" class="min-h-11 w-full sm:w-auto" data-test="casting-vote-{{ $round->id }}">{{ __('Stichentscheid eintragen') }}</flux:button>
                                    <flux:button wire:click="startTieRunOff({{ $round->id }})" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="tie-run-off-{{ $round->id }}">{{ __('Stichwahl anlegen') }}</flux:button>
                                {{-- The run-off of a missed majority is set up from the next-step card, the one place it is offered. --}}
                                @elseif ($report['run_off'] && ! $report['has_run_off'])
                                    <p class="min-w-0 text-sm text-text-secondary break-words sm:mr-4">{{ trans_choice('{1} Zwischen :names, um einen Sitz.|[2,*] Zwischen :names, um :count Sitze.', $report['run_off']['seats'], ['names' => implode(', ', $report['run_off']['candidates']), 'count' => $report['run_off']['seats']]) }}</p>
                                @endif
                                <flux:button wire:click="startRoundAction({{ $round->id }}, 'annul')" variant="ghost" class="min-h-11 w-full text-danger! sm:w-auto" data-test="annul-round-{{ $round->id }}">{{ __('Wahlgang annullieren') }}</flux:button>
                            </div>
                        @elseif ($report['state'] === \App\Enums\ElectionRoundState::Annulled)
                            <p class="mt-4 text-base text-text-primary break-words">
                                @if ($round->opened_at === null)
                                    {{ __('Nicht geöffnet, annulliert.') }}
                                @elseif ($round->closed_at !== null)
                                    {{-- Counted before it was annulled: the minutes keep the result, marked as void. --}}
                                    {{ trans_choice('{0} Ausgezählt ohne Stimme, dann annulliert. Das Ergebnis ist ungültig und steht so im Beschlussprotokoll.|{1} 1 Stimme ausgezählt, dann annulliert. Das Ergebnis ist ungültig und steht so im Beschlussprotokoll.|[2,*] :count Stimmen ausgezählt, dann annulliert. Das Ergebnis ist ungültig und steht so im Beschlussprotokoll.', $report['cast']) }}
                                @else
                                    {{ trans_choice('{0} Keine Stimme abgegeben, nicht ausgezählt.|{1} 1 Stimme abgegeben, nicht ausgezählt.|[2,*] :count Stimmen abgegeben, nicht ausgezählt.', $report['cast']) }}
                                @endif
                                @if ($report['reason'])
                                    {{ __('Grund: :reason', ['reason' => $report['reason']]) }}
                                @endif
                            </p>
                            @if ($this->canRepeat($round))
                                <div class="mt-5">
                                    <flux:button wire:key="repeat-round-{{ $round->id }}" wire:click="startRoundAction({{ $round->id }}, 'repeat')" icon="arrow-path" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="repeat-round-{{ $round->id }}">{{ __('Wahlgang wiederholen') }}</flux:button>
                                </div>
                            @endif
                        @elseif ($openReport !== null)
                            <p class="mt-4 text-sm text-text-secondary" data-test="open-round-waits-{{ $round->id }}">
                                {{ __('Öffnen geht, sobald „:round“ geschlossen ist.', ['round' => $openReport['round']->title]) }}
                            </p>
                        @else
                            <div class="mt-5">
                                <flux:button wire:key="open-round-{{ $round->id }}" wire:click="startRoundAction({{ $round->id }}, 'open')" icon="play" variant="outline" class="min-h-11 w-full sm:w-auto" data-test="open-round-{{ $round->id }}">{{ __('Wahlgang öffnen') }}</flux:button>
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($election->is_test)
        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between" data-test="live-purge">
            <p class="inline-flex items-start gap-2 text-base text-text-secondary">
                <flux:icon.beaker variant="mini" class="mt-0.5 size-5 shrink-0 text-warning" aria-hidden="true" />
                {{ __('Das ist eine Probewahl. Nach der Probe löscht ihr sie mit allen Stimmen und Protokollzeilen.') }}
            </p>
            <flux:modal.trigger name="purge-election">
                <flux:button variant="ghost" icon="trash" class="min-h-11 w-full shrink-0 text-danger! sm:w-auto" data-test="live-purge-election">{{ __('Probewahl löschen') }}</flux:button>
            </flux:modal.trigger>
        </div>
    @endif
</div>
