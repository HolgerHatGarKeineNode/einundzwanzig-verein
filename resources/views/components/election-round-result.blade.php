@props([
    'round',
    'result',
    'ballots' => 0,
    'voted' => false,
])

@use('App\Enums\ElectionCountingRule')
@use('App\Enums\ElectionOutcome')
@use('App\Models\MemberProposal')

{{-- The count of one sealed round. Numbers per candidate only — nothing in
     here names who voted, let alone how. --}}
@php
    $yesNo = $result->rule === ElectionCountingRule::YesNoPerCandidate;
    $nameOf = fn (int $id): string => MemberProposal::authorNameOf($round->candidates->firstWhere('id', $id)?->pleb);
    $elected = array_map($nameOf, $result->elected);
    $tied = array_map($nameOf, $result->tied);
@endphp

<div class="mt-3 rounded-lg border border-border-default bg-bg-surface p-4 sm:p-5">
    <p class="max-w-prose text-base leading-normal text-text-primary">
        @if ($elected !== [])
            {{ trans_choice('Gewählt ist :names.|Gewählt sind :names.', count($elected), ['names' => implode(', ', $elected)]) }}
        @endif
        @switch($result->outcome)
            @case(ElectionOutcome::Tie)
                {{ trans_choice('Gleichstand um :count Sitz zwischen :names. Wie es weitergeht, sagt der Vorstand im Call.|Gleichstand um :count Sitze zwischen :names. Wie es weitergeht, sagt der Vorstand im Call.', $result->tiedSeats, ['count' => $result->tiedSeats, 'names' => implode(', ', $tied)]) }}
                @break
            @case(ElectionOutcome::Understaffed)
                @if ($result->rule === ElectionCountingRule::SingleMajority && $elected === [])
                    {{ __('Niemand hat mehr als die Hälfte der Stimmen für Personen bekommen. Der Sitz ist nicht besetzt. Wie es weitergeht, sagt der Vorstand im Call.') }}
                @else
                    {{ __('Besetzt sind :filled von :seats Sitzen.', ['filled' => count($elected), 'seats' => $result->seats]) }}
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

    <p class="mt-3 text-sm leading-normal text-text-secondary">
        {{ __(':count Stimmzettel abgegeben, davon :blank leer.', ['count' => $ballots, 'blank' => $result->blank]) }}
        @if ($voted)
            {{ __('Deine Stimme ist dabei.') }}
        @endif
    </p>
</div>
