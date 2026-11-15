@props([
    'pubkey' => null,
])

{{-- A rehearsal for the meeting day, entirely in the browser: ask the signer
     for its key, compare it with the logged-in account and sign one throwaway
     event. Nothing is sent to the server or to a relay. The kind-22242 event
     carries no server challenge, so it cannot be used to log in anywhere. --}}
<section aria-labelledby="signer-check"
         class="rounded-lg border border-border-default bg-bg-surface p-5"
         x-data="{
             state: 'idle',
             async probe() {
                 this.state = 'busy';
                 try {
                     if (! window.nostr || typeof window.nostr.signEvent !== 'function') {
                         this.state = 'missing';
                         return;
                     }
                     const key = await window.nostr.getPublicKey();
                     if (@js($pubkey) && key !== @js($pubkey)) {
                         this.state = 'other';
                         return;
                     }
                     const signed = await window.nostr.signEvent({ kind: 22242, created_at: Math.floor(Date.now() / 1000), tags: [['challenge', 'probe-vereinswahl']], content: '' });
                     this.state = signed && signed.sig ? 'ok' : 'failed';
                 } catch (error) {
                     this.state = 'failed';
                 }
             },
         }">
    <h2 id="signer-check" class="text-lg font-bold text-text-primary">{{ __('Dein Nostr-Signer') }}</h2>
    <p class="mt-2 text-sm leading-normal text-text-secondary">
        {{ __('Am Tag der Versammlung unterschreibst du deine Stimme mit deinem Signer. Probier ihn vorher einmal aus, dann klappt es im Call.') }}
    </p>

    <flux:button x-on:click="probe()" x-bind:disabled="state === 'busy'" icon="finger-print" class="mt-4 min-h-11 w-full">
        {{ __('Signer ausprobieren') }}
    </flux:button>

    <div aria-live="polite" class="mt-3 text-sm leading-normal">
        <p x-show="state === 'busy'" x-cloak class="text-text-secondary">{{ __('Warte auf deinen Signer …') }}</p>
        <p x-show="state === 'ok'" x-cloak class="flex items-start gap-2 text-text-primary">
            <flux:icon.check-circle variant="mini" class="mt-0.5 shrink-0 text-success" aria-hidden="true" />
            <span>{{ __('Klappt. Dein Signer unterschreibt für dieses Konto.') }}</span>
        </p>
        <p x-show="state === 'other'" x-cloak class="flex items-start gap-2 text-text-primary">
            <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 shrink-0 text-orange-500" aria-hidden="true" />
            <span>{{ __('Dein Signer gehört zu einem anderen Konto als dein Login. Wechsle im Signer auf das Konto, mit dem du angemeldet bist.') }}</span>
        </p>
        <p x-show="state === 'missing' || state === 'failed'" x-cloak class="flex items-start gap-2 text-text-primary">
            <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 shrink-0 text-orange-500" aria-hidden="true" />
            <span x-text="state === 'missing' ? @js(__('Kein Signer gefunden.')) : @js(__('Nicht unterschrieben. Abgebrochen oder der Signer antwortet nicht.'))"></span>
        </p>
    </div>

    <details class="group mt-3 text-sm">
        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-md text-text-primary underline decoration-neutral-500 underline-offset-4 hover:decoration-orange-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 [&::-webkit-details-marker]:hidden">
            <flux:icon.chevron-right variant="micro" class="shrink-0 transition-transform duration-150 group-open:rotate-90 motion-reduce:transition-none" aria-hidden="true" />
            {{ __('So richtest du einen Signer ein') }}
        </summary>
        <ul class="mt-2 space-y-2 pl-6 leading-normal text-text-secondary [&>li]:list-disc">
            <li>{{ __('Am Computer: eine Browser-Erweiterung wie Alby oder nos2x installieren und deinen Nostr-Schlüssel dort hinterlegen.') }}</li>
            <li>{{ __('Am Telefon oder ohne Erweiterung: über den Knopf „Connect with Nostr“ unten auf der Seite eine Signer-App verbinden, zum Beispiel Amber unter Android.') }}</li>
            <li>{{ __('Danach hier noch einmal „Signer ausprobieren“ wählen.') }}</li>
        </ul>
    </details>
</section>
