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
             attempt: 0,
             async probe(force = false) {
                 const signer = window.nostrSigner;
                 if (! force && signer.signerKind() === 'none') {
                     this.state = 'missing';
                     $nextTick(() => $refs.help?.focus());
                     return;
                 }
                 const attempt = ++this.attempt;
                 this.state = 'busy';
                 try {
                     const key = await signer.withTimeout(window.nostr.getPublicKey(), 60000);
                     if (attempt !== this.attempt) return;
                     if (@js($pubkey) && key !== @js($pubkey)) {
                         this.state = 'other';
                         return;
                     }
                     const signed = await signer.withTimeout(window.nostr.signEvent({ kind: 22242, created_at: Math.floor(Date.now() / 1000), tags: [['challenge', 'probe-vereinswahl']], content: '' }), 60000);
                     if (attempt !== this.attempt) return;
                     this.state = signed && signed.sig ? 'ok' : 'failed';
                 } catch (error) {
                     if (attempt !== this.attempt) return;
                     this.state = error?.message === 'timeout' ? 'timeout' : 'failed';
                 }
             },
         }">
    <h2 id="signer-check" class="text-lg font-bold text-text-primary">{{ __('Dein Nostr-Signer') }}</h2>
    <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">
        {{ __('Am Tag der Versammlung unterschreibst du deine Stimme mit deinem Signer.') }}
        {{ __('Probier ihn vorher einmal aus, dann klappt es im Call.') }}
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
        <p x-show="state === 'missing' || state === 'failed' || state === 'timeout'" x-cloak class="flex items-start gap-2 text-text-primary">
            <flux:icon.exclamation-triangle variant="mini" class="mt-0.5 shrink-0 text-orange-500" aria-hidden="true" />
            <span x-text="{ missing: @js(__('Auf diesem Gerät ist noch kein Nostr-Signer eingerichtet. So bekommst du einen:')), timeout: @js(__('Dein Signer hat nach einer Minute nicht geantwortet. Öffne ihn, bestätige dort und probier es noch einmal.')), failed: @js(__('Nicht unterschrieben. Abgebrochen oder der Signer antwortet nicht.')) }[state] ?? ''"></span>
        </p>
    </div>

    <div x-show="state === 'missing'" x-cloak class="mt-3">
        <x-election-signer-help id="signer-check-help" x-ref="help" bare connect="probe(true)" />
    </div>

    <details class="group mt-3 text-sm" x-show="state !== 'missing'">
        <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-md text-text-primary underline decoration-neutral-500 underline-offset-4 hover:decoration-orange-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 [&::-webkit-details-marker]:hidden">
            <flux:icon.chevron-right variant="micro" class="shrink-0 transition-transform duration-150 group-open:rotate-90 motion-reduce:transition-none" aria-hidden="true" />
            {{ __('So richtest du einen Signer ein') }}
        </summary>
        <x-election-signer-help id="signer-check-howto" class="mt-2" bare connect="probe(true)" />
    </details>
</section>
