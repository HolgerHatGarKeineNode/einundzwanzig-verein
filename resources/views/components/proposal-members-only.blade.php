@props([
    'heading' => null,
])

{{-- Shown instead of the member proposal pages to everybody who may not read
     them. The pages name their authors, so they are not public. The rule
     spelled out here is MemberProposalPolicy::isParticipant(). --}}
<div class="w-full max-w-2xl mx-auto">
    <flux:callout variant="warning" icon="exclamation-circle">
        <flux:callout.heading>{{ $heading ?? __('Vorschläge sind nur für Mitglieder sichtbar') }}</flux:callout.heading>
        <flux:callout.text>
            @if (! \App\Support\NostrAuth::check())
                {{ __('Bitte melde dich zunächst mit Nostr an.') }}
            @else
                {{ __('Mitmachen können Ehrenmitglieder sowie aktive und passive Mitglieder, die den Beitrag für :year oder :previousYear bezahlt haben.', ['year' => now()->year, 'previousYear' => now()->year - 1]) }}
            @endif
        </flux:callout.text>
        @if (\App\Support\NostrAuth::check())
            <x-slot name="actions">
                <flux:button :href="route('association.profile')" class="min-h-11" wire:navigate>{{ __('Meine Mitgliedschaft') }}</flux:button>
            </x-slot>
        @endif
    </flux:callout>
</div>
