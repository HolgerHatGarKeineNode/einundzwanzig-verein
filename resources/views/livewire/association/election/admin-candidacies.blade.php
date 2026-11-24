{{-- Candidacies of the selected election, per round. Included by admin.blade.php with $election. --}}
@php
    $statusWords = [
        \App\Enums\ElectionCandidateStatus::Pending->value => [__('wartet auf Prüfung'), 'clock', 'text-warning'],
        \App\Enums\ElectionCandidateStatus::Approved->value => [__('freigegeben'), 'check-circle', 'text-success'],
        \App\Enums\ElectionCandidateStatus::Rejected->value => [__('abgelehnt'), 'x-circle', 'text-text-tertiary'],
        \App\Enums\ElectionCandidateStatus::Withdrawn->value => [__('zurückgezogen'), 'arrow-uturn-left', 'text-text-tertiary'],
    ];
@endphp

<div class="relative space-y-10" data-test="candidacies">
    @if ($election->announced_at === null)
        <p class="max-w-2xl text-base text-text-secondary">{{ __('Mitglieder kandidieren auf der Wahlseite, sobald die Wahl angekündigt ist. Bis dahin bleibt diese Liste leer.') }}</p>
    @else
        <p class="max-w-2xl text-base text-text-secondary">{{ __('Freigegebene Kandidaturen stehen in der Sitzung auf dem Stimmzettel. Ablehnen und Zurückziehen brauchen einen Grund; er steht im Protokoll der Wahl.') }}</p>
    @endif

    @foreach ($this->candidaciesByRound as $group)
        @php
            $round = $group['round'];
            $isDraft = $round->stateAt(now()) === \App\Enums\ElectionRoundState::Draft;
        @endphp
        <section wire:key="candidacies-round-{{ $round->id }}" aria-labelledby="candidacies-round-{{ $round->id }}" data-test="candidacies-round">
            <h3 id="candidacies-round-{{ $round->id }}" class="text-lg font-semibold text-text-primary break-words">
                {{ $loop->iteration }}. {{ $round->title }}
            </h3>
            <p class="mt-1 text-sm text-text-tertiary">
                {{ trans_choice('{1} Ein Sitz|[2,*] :count Sitze', $round->seats, ['count' => $round->seats]) }}, {{ $round->counting_rule->label() }}
            </p>

            @unless ($isDraft)
                <p class="mt-3 text-sm text-text-secondary">{{ __('Dieser Wahlgang ist geöffnet. Die Kandidaturen sind fest.') }}</p>
            @endunless

            @if ($group['candidates']->isEmpty())
                <p class="mt-4 rounded-lg border border-dashed border-border-default px-6 py-10 text-base text-text-secondary">{{ __('Noch keine Kandidatur.') }}</p>
            @else
                <ul class="mt-4 divide-y divide-border-default rounded-lg border border-border-default bg-bg-surface">
                    @foreach ($group['candidates'] as $row)
                        @php
                            $candidate = $row['candidate'];
                            [$word, $icon, $tone] = $statusWords[$candidate->status->value];
                            $pending = $candidate->status === \App\Enums\ElectionCandidateStatus::Pending;
                        @endphp
                        <li wire:key="candidate-{{ $candidate->id }}" class="relative grid gap-4 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start sm:p-5" data-test="candidate-{{ $candidate->id }}">
                            <div class="min-w-0">
                                <p class="text-base font-bold text-text-primary break-words"><x-nostr-profile :pubkey="$candidate->pleb?->pubkey" :name="$row['name']" :picture="$candidate->pleb?->profile?->picture" /></p>
                                <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                    <span class="inline-flex items-center gap-1.5 {{ $tone }}" data-test="candidate-status">
                                        <flux:icon :name="$icon" variant="micro" class="size-4" aria-hidden="true" />
                                        {{ $word }}
                                    </span>
                                    @if ($pending && ! $row['entitled'])
                                        <span class="inline-flex items-center gap-1.5 text-warning" data-test="candidate-fee-missing">
                                            <flux:icon.exclamation-triangle variant="micro" class="size-4" aria-hidden="true" />
                                            {{ __(':missing, Freigabe erst nach der Zahlung', ['missing' => $election->fee_rule->missingText($election->fee_year)]) }}
                                        </span>
                                    @endif
                                </div>
                                @if (filled($candidate->statement))
                                    <details class="group mt-2">
                                        <summary class="inline-flex min-h-11 cursor-pointer items-center text-sm text-text-secondary underline underline-offset-4 hover:text-text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                                            {{ __('Vorstellung lesen') }}
                                        </summary>
                                        <div class="mt-2 max-w-prose whitespace-pre-line break-words text-base text-text-primary">{{ $candidate->statement }}</div>
                                    </details>
                                @endif
                            </div>

                            @if ($isDraft && in_array($candidate->status, [\App\Enums\ElectionCandidateStatus::Pending, \App\Enums\ElectionCandidateStatus::Approved], true))
                                <div class="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                                    @if ($pending)
                                        <flux:button wire:click="approve({{ $candidate->id }})" icon="check" class="min-h-11" :disabled="! $row['entitled']" data-test="approve-{{ $candidate->id }}">{{ __('Freigeben') }}</flux:button>
                                        <flux:button wire:click="startDecision({{ $candidate->id }}, 'reject')" variant="ghost" class="min-h-11" data-test="reject-{{ $candidate->id }}">{{ __('Ablehnen') }}</flux:button>
                                    @else
                                        <flux:button wire:click="startDecision({{ $candidate->id }}, 'withdraw')" variant="ghost" class="col-span-2 min-h-11" data-test="withdraw-{{ $candidate->id }}">{{ __('Zurückziehen') }}</flux:button>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>
