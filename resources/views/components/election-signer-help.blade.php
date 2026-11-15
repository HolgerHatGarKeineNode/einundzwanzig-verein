@props([
    'id',
    // Alpine expression for "Signer-App verbinden", or null to leave the button out.
    'connect' => null,
    // Inside another box (the signer check): no frame of its own.
    'bare' => false,
])

{{-- German help for a member without a Nostr signer on this device. It comes
     before the English connect dialog of window.nostr.js, which offers an
     `nsec1…` field and would otherwise be the first thing such a member meets.
     The devices are a choice, not a sequence: no numbers. --}}
<section id="{{ $id }}" data-signer-help tabindex="-1" aria-labelledby="{{ $id }}-heading"
         {{ $attributes->class(['text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500', 'rounded-lg border border-border-default bg-bg-surface p-5' => ! $bare]) }}>
    <h3 id="{{ $id }}-heading" class="text-lg font-bold leading-snug text-text-primary">{{ __('Noch kein Nostr-Signer?') }}</h3>
    <p class="mt-2 max-w-prose text-sm leading-normal text-text-secondary">
        {{ __('Ein Signer bewahrt deinen geheimen Nostr-Schlüssel auf und unterschreibt für dich. Diese Seite bittet ihn nur um eine Unterschrift, den Schlüssel selbst bekommt sie nie zu sehen.') }}
    </p>

    <dl class="mt-4 space-y-3 text-sm leading-normal">
        <div>
            <dt class="font-bold text-text-primary">{{ __('Am Computer') }}</dt>
            <dd class="text-text-secondary">{{ __('Die Browser-Erweiterung Alby oder nos2x installieren, deinen Schlüssel dort hinterlegen und diese Seite neu laden.') }}</dd>
        </div>
        <div>
            <dt class="font-bold text-text-primary">{{ __('Am iPhone oder iPad') }}</dt>
            <dd class="text-text-secondary">{{ __('Die App Nostash aus dem App Store installieren, in Safari unter Erweiterungen einschalten und diese Seite neu laden.') }}</dd>
        </div>
        <div>
            <dt class="font-bold text-text-primary">{{ __('Am Android-Telefon') }}</dt>
            <dd class="text-text-secondary">{{ __('Die App Amber installieren. Dann „Signer-App verbinden“ wählen und im englischen Fenster den bunker-Link aus Amber einfügen.') }}</dd>
        </div>
    </dl>

    <p class="mt-4 flex max-w-prose items-start gap-2 border-l-4 border-orange-500 pl-3 text-sm font-bold leading-normal text-text-primary">
        <flux:icon.shield-exclamation variant="mini" class="mt-0.5 shrink-0 text-orange-500" aria-hidden="true" />
        <span>{{ __('Gib nie deinen nsec in eine Webseite ein, auch nicht in das Feld im Verbindungsfenster. Der Verein fragt nie danach.') }}</span>
    </p>

    @if ($connect)
        <flux:button icon="link" class="mt-4 min-h-11 w-full sm:w-auto" x-on:click="{{ $connect }}">
            {{ __('Signer-App verbinden') }}
        </flux:button>
    @endif
</section>
