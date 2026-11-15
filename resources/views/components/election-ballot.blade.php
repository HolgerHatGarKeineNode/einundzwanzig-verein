@props([
    'round',
    'pubkey' => null,
])

@use('App\Enums\ElectionCountingRule')
@use('App\Models\MemberProposal')

@php
    $rule = $round->counting_rule;
    $max = $rule->maxSelections($round->candidates->modelKeys(), $round->seats);
    $instruction = match ($rule) {
        ElectionCountingRule::SingleMajority => __('Kreuze eine Person an. Du kannst den Stimmzettel auch leer abgeben.'),
        ElectionCountingRule::MostVotes => trans_choice('Kreuze höchstens :count Person an. Ohne Kreuz gibst du den Stimmzettel leer ab.|Kreuze höchstens :count Personen an. Ohne Kreuz gibst du den Stimmzettel leer ab.', $max, ['count' => $max]),
        ElectionCountingRule::YesNoPerCandidate => __('Kreuze bei jeder Person Ja, Nein oder Enthaltung an. Ohne Kreuz zählt es als Enthaltung.'),
    };
    $messages = [
        'tooMany' => trans_choice('Du kannst höchstens :count Person ankreuzen. Nimm erst ein Kreuz weg.|Du kannst höchstens :count Personen ankreuzen. Nimm erst ein Kreuz weg.', $max, ['count' => $max]),
        'noSigner' => __('Kein Nostr-Signer gefunden. Installiere eine Browser-Erweiterung wie Alby oder nos2x, oder verbinde über den Knopf „Connect with Nostr“ unten eine Signer-App. Deine Auswahl bleibt stehen.'),
        'cancelled' => __('Nicht signiert: Du hast im Signer abgebrochen, oder er hat nicht geantwortet. Deine Stimme ist nicht abgegeben. Du kannst es noch einmal versuchen.'),
        'otherAccount' => __('Dein Signer gehört zu einem anderen Nostr-Konto als dein Login. Wechsle im Signer auf das Konto, mit dem du angemeldet bist, und signiere noch einmal. Deine Stimme ist nicht abgegeben.'),
        'lineNone' => __('Noch kein Kreuz: Das zählt als Enthaltung bei allen.'),
        'lineMarked' => __('Bei :count angekreuzt, der Rest zählt als Enthaltung.'),
        'lineBlank' => __('Kein Kreuz: Du gibst den Stimmzettel leer ab.'),
        'lineCount' => __(':count von höchstens :max angekreuzt.'),
        'noAnswer' => __('Der Server antwortet gerade nicht. Bitte versuche es noch einmal.'),
        'notArrived' => __('Deine Stimme ist nicht angekommen. Die Verbindung war unterbrochen. Sende sie noch einmal, sie ist schon signiert.'),
        'stillNoAnswer' => __('Keine Verbindung zum Server. Ob deine Stimme angekommen ist, lässt sich gerade nicht prüfen. Sende sie noch einmal, sobald du wieder verbunden bist. Doppelt gezählt wird sie nicht.'),
    ];
    $sheetFocus = 'focus-visible:outline-3 focus-visible:outline-offset-2 focus-visible:outline-ink';
@endphp

{{-- The ballot sheet: a light page in the dark portal (decision E13), so the
     act of voting looks like nothing else on the site. The selection lives in
     Alpine only and reaches the server once, as the signed event. --}}
<div class="relative mt-3 -ml-14 bg-bg-page sm:ml-0 sm:bg-transparent"
     x-data="electionBallot({ roundId: {{ $round->id }}, pubkey: @js($pubkey), rule: @js($rule->value), max: {{ $max }}, messages: @js($messages) })">

    {{-- Step 1: fill in --}}
    <form x-show="step === 'fill'" x-on:submit.prevent="review()" novalidate>
        <fieldset x-ref="sheet" tabindex="-1" class="ballot-sheet min-w-0 rounded-md bg-paper text-ink shadow-[0_1px_0_rgb(255_255_255/0.6)_inset] {{ $sheetFocus }}">
            <legend class="sr-only">{{ __('Stimmzettel: :office', ['office' => $round->title]) }}</legend>
            <div class="border-b-2 border-ink px-4 pb-3 pt-4 sm:px-6">
                <p class="text-xl font-bold leading-tight">{{ __('Stimmzettel') }}</p>
                <p class="mt-1 text-base leading-normal">{{ $round->parent_round_id ? __('Stichwahl: :office', ['office' => $round->title]) : $round->title }}</p>
                <p class="mt-2 text-sm leading-normal text-ink-soft">{{ $instruction }}</p>
            </div>

            <ul class="divide-y divide-paper-rule">
                @foreach ($round->candidates as $candidate)
                    @php $name = MemberProposal::authorNameOf($candidate->pleb); @endphp
                    <li wire:key="ballot-{{ $round->id }}-{{ $candidate->id }}" class="px-4 sm:px-6">
                        @if ($rule === ElectionCountingRule::YesNoPerCandidate)
                            <div role="radiogroup" aria-label="{{ $name }}" class="py-3">
                                <p class="break-words text-lg font-bold leading-snug">{{ $name }}</p>
                                <div class="mt-2 flex flex-wrap gap-x-6 gap-y-1">
                                    @foreach (['yes' => __('Ja'), 'no' => __('Nein'), 'abstain' => __('Enthaltung')] as $value => $label)
                                        <label class="flex min-h-11 cursor-pointer items-center gap-3">
                                            <input type="radio" class="peer sr-only" name="ballot-{{ $round->id }}-{{ $candidate->id }}" value="{{ $value }}" x-model="selection[{{ $candidate->id }}]">
                                            <x-election-ballot-mark round />
                                            <span class="text-base">{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @elseif ($rule === ElectionCountingRule::SingleMajority)
                            <label class="flex min-h-14 cursor-pointer items-center gap-4 py-3">
                                <input type="radio" class="peer sr-only" name="ballot-{{ $round->id }}" value="{{ $candidate->id }}" x-model="single">
                                <x-election-ballot-mark />
                                <span class="min-w-0 break-words text-lg font-bold leading-snug">{{ $name }}</span>
                            </label>
                        @else
                            <label class="flex min-h-14 cursor-pointer items-center gap-4 py-3">
                                <input type="checkbox" class="peer sr-only" value="{{ $candidate->id }}"
                                       x-bind:checked="selection[{{ $candidate->id }}] === 'for'"
                                       x-on:change="toggle({{ $candidate->id }}, $event)">
                                <x-election-ballot-mark />
                                <span class="min-w-0 break-words text-lg font-bold leading-snug">{{ $name }}</span>
                            </label>
                        @endif
                    </li>
                @endforeach
                @if ($rule === ElectionCountingRule::SingleMajority)
                    <li class="px-4 sm:px-6">
                        <label class="flex min-h-14 cursor-pointer items-center gap-4 py-3">
                            <input type="radio" class="peer sr-only" name="ballot-{{ $round->id }}" value="blank" x-model="single">
                            <x-election-ballot-mark />
                            <span class="text-base">{{ __('Leer abgeben, für keine Person') }}</span>
                        </label>
                    </li>
                @endif
            </ul>
        </fieldset>

        <p x-show="notice" x-text="notice" x-cloak role="status" class="mt-3 text-sm leading-normal text-warning"></p>

        <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
            <flux:button type="submit" variant="primary" icon="eye" class="min-h-11 w-full sm:w-auto" x-bind:disabled="busy">
                {{ __('Prüfen') }}
            </flux:button>
            <p class="text-sm text-text-secondary" x-text="summaryLine()"></p>
        </div>
    </form>

    {{-- Step 2: review, sign, send --}}
    <div x-show="step !== 'fill'" x-cloak>
        <div class="rounded-md bg-paper px-4 py-4 text-ink sm:px-6">
            <h5 x-ref="reviewHeading" tabindex="-1" class="text-xl font-bold leading-tight {{ $sheetFocus }}">{{ __('Prüfe deine Stimme') }}</h5>
            <p class="mt-2 max-w-prose text-sm leading-normal text-ink-soft">
                {{ __('Genau diesen Text signierst du mit deinem Nostr-Schlüssel. Die Stimme geht nur an den Verein, nicht an Relays. Abgegeben ist abgegeben: Ändern geht danach nicht mehr.') }}
            </p>
            <pre class="mt-4 whitespace-pre-wrap break-words border-l-4 border-ink py-1 pl-4 font-sans text-base font-bold leading-relaxed" x-text="content"></pre>
        </div>

        <div aria-live="polite" class="mt-3 text-base leading-normal">
            <p x-show="step === 'signing'" class="text-text-secondary">{{ __('Warte auf deinen Signer …') }}</p>
            <p x-show="step === 'sending'" class="text-text-secondary">{{ __('Deine Stimme wird übermittelt …') }}</p>
            <p x-show="step === 'checking'" class="text-text-secondary">{{ __('Keine Antwort vom Server. Ich prüfe, ob deine Stimme angekommen ist …') }}</p>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
            <flux:button variant="primary" icon="finger-print" class="min-h-11 w-full sm:w-auto"
                         x-show="step === 'review' || step === 'signing'" x-on:click="sign()" x-bind:disabled="step !== 'review'">
                {{ __('Signieren und abgeben') }}
            </flux:button>
            <flux:button variant="primary" icon="arrow-path" class="min-h-11 w-full sm:w-auto"
                         x-show="step === 'lost'" x-on:click="send()">
                {{ __('Noch einmal senden') }}
            </flux:button>
            <flux:button variant="ghost" icon="arrow-uturn-left" class="min-h-11 w-full sm:w-auto"
                         x-show="step === 'review'" x-on:click="back()">
                {{ __('Auswahl ändern') }}
            </flux:button>
        </div>
    </div>

    {{-- One live region for every message of both steps: always in the DOM,
         so a screen reader announces the text the moment it changes. --}}
    <div role="alert">
        <template x-if="error">
            <div class="mt-3 flex max-w-prose items-start gap-2 rounded-md border border-orange-800 bg-bg-surface p-4 text-base leading-normal text-text-primary">
                <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-orange-500" aria-hidden="true" />
                <span x-text="error"></span>
            </div>
        </template>
    </div>
</div>
