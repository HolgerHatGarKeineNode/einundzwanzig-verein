@props([
    'heading' => null,
])

{{-- Shown instead of the member proposal pages to everybody who may not read
     them. The pages name their authors, so they are not public. --}}
<div class="w-full max-w-[1600px] mx-auto">
    <flux:callout variant="warning" icon="exclamation-circle">
        <flux:callout.heading>{{ $heading ?? __('Vorschläge sind nur für Mitglieder sichtbar') }}</flux:callout.heading>
        <flux:callout.text>
            @if (! \App\Support\NostrAuth::check())
                {{ __('Bitte melde dich zunächst mit Nostr an.') }}
            @else
                {{ __('Dafür brauchst du eine Mitgliedschaft mit bezahltem Beitrag für das Jahr :year.', ['year' => now()->year]) }}
            @endif
        </flux:callout.text>
    </flux:callout>
</div>
