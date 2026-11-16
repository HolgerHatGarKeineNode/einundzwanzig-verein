@props([
    'round',
    'result',
    'ballots' => 0,
    'voted' => false,
    // 'decided' | 'pending' | null — what the run-off below this round did.
    'runOff' => null,
    // The event ids of every vote of the round, sorted (plan decision E4).
    'receipts' => [],
    // The viewer's own vote event id, if they voted.
    'ownEventId' => null,
])

@use('App\Enums\ElectionCountingRule')
@use('App\Enums\ElectionOutcome')
@use('App\Enums\ElectionTieKind')
@use('App\Models\MemberProposal')
@use('App\Support\ElectionTieText')

{{-- The effective result of one sealed round: the count, with the chair's
     casting vote applied once it is recorded. Numbers per candidate only —
     nothing in here names who voted, let alone how. --}}
@php
    $yesNo = $result->rule === ElectionCountingRule::YesNoPerCandidate;
    $nameOf = fn (int $id): string => MemberProposal::authorNameOf($round->candidates->firstWhere('id', $id)?->pleb);
    $elected = array_map($nameOf, $result->elected);
    $tied = array_map($nameOf, $result->tied);
    // A name may end in a full stop ("Erik E."); the sentence must not add a second one.
    $sentence = fn (string $text): string => preg_replace('/(?<=\S)\.\.(?=\s|$)/u', '.', $text);
    $next = match ($runOff) {
        'decided' => __('Entschieden hat die Stichwahl darunter.'),
        'pending' => __('Dazu gibt es eine Stichwahl, sie steht darunter.'),
        default => __('Wie es weitergeht, sagt der Vorstand im Call.'),
    };
@endphp

<div class="mt-3 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-5">
    <p class="max-w-prose wrap-anywhere text-base leading-normal text-text-primary">
        @if ($elected !== [])
            {{ $sentence(trans_choice('Gewählt ist :names.|Gewählt sind :names.', count($elected), ['names' => implode(', ', $elected)])) }}
        @endif
        @if ($result->castingVote !== null)
            {{-- The sentence above names who is elected; the decision says only who decided, and the chair stands on a line of its own. A 60-character chair inside one sentence with a 63-character name ran 7 lines at 390 px (checklist Q12: at most 3). --}}
            <span class="block" data-test="casting-vote-{{ $round->id }}">{{ __('Entschieden durch Stichentscheid.') }}</span>
            <span class="block text-text-secondary">{{ __('Vorsitz: :chair', ['chair' => $result->castingVote->chair]) }}</span>
        @endif
        @switch($result->outcome)
            @case(ElectionOutcome::Tie)
                @if ($result->tieKind === ElectionTieKind::YesEqualsNo)
                    {{ $sentence(ElectionTieText::yesEqualsNo($tied)) }}
                @else
                    {{ $sentence(trans_choice('Gleichstand um :count Sitz zwischen :names.|Gleichstand um :count Sitze zwischen :names.', $result->tiedSeats, ['count' => $result->tiedSeats, 'names' => implode(', ', $tied)])) }}
                @endif
                {{ $runOff === null ? ElectionTieText::pending() : ElectionTieText::runOffResolved().' '.$next }}
                @break
            @case(ElectionOutcome::Understaffed)
                @if ($result->rule === ElectionCountingRule::SingleMajority && $elected === [])
                    {{ __('Niemand hat mehr als die Hälfte der Stimmen für Personen bekommen.') }}
                    {{ match ($runOff) { 'decided' => __('Den Sitz hat die Stichwahl darunter besetzt.'), 'pending' => __('In diesem Wahlgang ist der Sitz nicht besetzt worden.').' '.$next, default => __('Der Sitz ist nicht besetzt.').' '.$next } }}
                @else
                    {{ __('Besetzt sind :filled von :seats Sitzen.', ['filled' => count($elected), 'seats' => $result->seats]) }}
                    @if ($runOff !== null)
                        {{ $next }}
                    @endif
                @endif
                @break
        @endswitch
    </p>

    <table class="mt-4 w-full border-collapse text-base">
        <caption class="sr-only">{{ __('Stimmen je Person: :office', ['office' => $round->title]) }}</caption>
        <thead>
            <tr class="border-b border-border-default text-left text-sm text-text-secondary">
                <th scope="col" class="py-2 pr-4 font-normal">{{ __('Person') }}</th>
                @if ($yesNo)
                    <th scope="col" class="py-2 pl-4 text-right font-normal">{{ __('Ja') }}</th>
                    <th scope="col" class="py-2 pl-4 text-right font-normal">{{ __('Nein') }}</th>
                @else
                    <th scope="col" class="py-2 pl-4 text-right font-normal">{{ __('Stimmen') }}</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-border-subtle">
            @foreach ($round->candidates as $candidate)
                @php
                    $counts = $result->counts[$candidate->id] ?? ['for' => 0, 'yes' => 0, 'no' => 0];
                    $isElected = in_array($candidate->id, $result->elected, true);
                    $isTied = in_array($candidate->id, $result->tied, true);
                @endphp
                <tr wire:key="result-{{ $round->id }}-{{ $candidate->id }}">
                    <th scope="row" class="py-3 pr-4 text-left font-normal">
                        <span @class(['wrap-anywhere', 'font-bold text-text-primary' => $isElected, 'text-text-primary' => ! $isElected])>{{ MemberProposal::authorNameOf($candidate->pleb) }}</span>
                        @if ($isElected)
                            <span class="ml-1 inline-flex items-center gap-1 whitespace-nowrap text-sm text-success">
                                <flux:icon.check variant="micro" aria-hidden="true" />{{ __('gewählt') }}
                            </span>
                        @elseif ($isTied)
                            <span class="ml-1 inline-flex items-center gap-1 whitespace-nowrap text-sm text-warning">
                                <flux:icon.scale variant="micro" aria-hidden="true" />{{ __('Gleichstand') }}
                            </span>
                        @endif
                    </th>
                    @if ($yesNo)
                        <td class="py-3 pl-4 text-right tabular-nums text-text-primary">{{ $counts['yes'] }}</td>
                        <td class="py-3 pl-4 text-right tabular-nums text-text-primary">{{ $counts['no'] }}</td>
                    @else
                        <td class="py-3 pl-4 text-right tabular-nums text-text-primary">{{ $counts['for'] }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="mt-3 max-w-prose text-sm leading-normal text-text-secondary">
        {{ trans_choice(':count Stimmzettel abgegeben, davon :blank Enthaltung (leer).|:count Stimmzettel abgegeben, davon :blank Enthaltungen (leer).', $result->blank, ['count' => $ballots, 'blank' => $result->blank]) }}
        @if ($ownEventId !== null && in_array($ownEventId, $receipts, true))
            {{ __('Deine Kennung') }} <span class="font-bold text-text-primary tabular-nums">{{ substr($ownEventId, 0, 8).'…'.substr($ownEventId, -8) }}</span> {{ __('steht in der Liste.') }}
        @elseif ($voted)
            {{ __('Deine Stimme ist dabei.') }}
        @endif
    </p>

    {{-- The published list of the round (plan decision E4): every vote event
         id, sorted, collapsed. The member compares in the browser — the own
         Kennung is marked, and the search below never sends a request. An id
         is a hash over the signed vote with a secret nonce from the member's
         browser (VoteEvent), so it shows that a vote is in, not what it says.
         wire:ignore.self keeps the list open across a poll's re-render. --}}
    @if ($receipts !== [])
        <details class="group mt-3" wire:ignore.self data-test="receipts-{{ $round->id }}"
                 x-data="{
                     query: '',
                     ids: @js($receipts),
                     get needle() { return this.query.toLowerCase().replace(/\s+/g, '').replace(/\.{2,}/g, '…') },
                     matches(id) {
                         const needle = this.needle
                         if (needle.includes('…')) {
                             const [head, tail] = needle.split('…')
                             return head.length + tail.length >= 8 && id.startsWith(head) && id.endsWith(tail)
                         }
                         return needle.length >= 8 && id.includes(needle)
                     },
                     get verdict() {
                         {{-- Semicolons: PHP swallows the newline after each @js's closing tag. --}}
                         if (this.needle === '') return '';
                         if (this.needle.replace('…', '').length < 8) return @js(__('Gib mindestens acht Zeichen der Kennung ein.'));
                         return this.ids.some((id) => this.matches(id)) ? @js(__('Gefunden: Diese Kennung steht in der Liste.')) : @js(__('Diese Kennung steht nicht in der Liste.'));
                     },
                 }">
            <summary class="inline-flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-md text-base text-text-primary underline decoration-neutral-500 underline-offset-4 hover:decoration-orange-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 [&::-webkit-details-marker]:hidden">
                <flux:icon.chevron-right variant="micro" class="shrink-0 transition-transform duration-150 group-open:rotate-90 motion-reduce:transition-none" aria-hidden="true" />
                {{ trans_choice('Die Kennung ansehen (:count)|Alle :count Kennungen ansehen', count($receipts), ['count' => count($receipts)]) }}
            </summary>

            <div class="mt-3 space-y-4">
                <p class="max-w-prose text-sm leading-normal text-text-secondary">
                    {{ trans_choice('Steht deine Kennung hier, ist deine Stimme gezählt.|Steht deine Kennung hier, ist deine Stimme unter den :count gezählten.', count($receipts), ['count' => count($receipts)]) }}
                    {{ __('Was du gewählt hast, verrät sie nicht.') }}
                </p>

                <div class="max-w-sm">
                    <flux:input x-model="query" :label="__('Kennung suchen')" autocomplete="off" spellcheck="false" class:input="min-h-11 tabular-nums" data-test="receipt-search" />
                    <p class="mt-2 min-h-6 text-sm leading-normal text-text-primary" aria-live="polite" x-text="verdict" data-test="receipt-verdict"></p>
                </div>

                <ol class="grid grid-cols-[repeat(auto-fill,minmax(12rem,1fr))] gap-2 text-sm tabular-nums" aria-label="{{ __('Kennungen aller Stimmen, sortiert') }}">
                    @foreach ($receipts as $receipt)
                        @php $isOwn = $receipt === $ownEventId; @endphp
                        <li wire:key="receipt-{{ $round->id }}-{{ $loop->index }}" data-receipt="{{ $receipt }}" @if ($isOwn) data-own-receipt @endif
                            x-bind:class="matches(@js($receipt)) && 'outline-2 outline-orange-500'"
                            @class([
                                'flex min-h-8 items-center justify-between gap-2 rounded-sm border px-2',
                                'border-orange-500 font-bold text-text-primary' => $isOwn,
                                'border-border-subtle text-text-secondary' => ! $isOwn,
                            ])>
                            <span title="{{ $receipt }}">{{ substr($receipt, 0, 8).'…'.substr($receipt, -8) }}</span>
                            @if ($isOwn)
                                <span class="text-text-primary">{{ __('deine') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </div>
        </details>
    @endif
</div>
