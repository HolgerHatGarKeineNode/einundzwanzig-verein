export default () => ({
    // Toggled while window.nostr.signEvent is awaiting the wallet so the
    // button disables itself, the loading overlay renders, and downstream
    // wire:poll consumers can pause if needed. Stays true through the
    // backend round-trip — the auth-button component reloads the page on
    // success, so resetting the flag here would just flicker the overlay
    // away while navigation is already underway.
    nostrLoginInProgress: false,

    /**
     * Resolve the active NIP-42 login challenge issued by the auth-button
     * Volt component. Falls back to the Livewire $wire proxy and then to a
     * server-side re-issue so the user does not have to reload if the
     * rendered snapshot drifted out of sync with the session (e.g. a tab
     * left open past the challenge TTL).
     */
    async resolveChallenge() {
        const fromDataset = this.$root?.dataset?.nostrChallenge;
        if (typeof fromDataset === 'string' && fromDataset !== '') {
            return fromDataset;
        }

        const livewireComponent = this.$el.closest('[wire\\:id]')?.__livewire;
        const fromWire = livewireComponent?.$wire?.nostrChallenge;
        if (typeof fromWire === 'string' && fromWire !== '') {
            return fromWire;
        }

        if (livewireComponent?.$wire?.requestNostrChallenge) {
            try {
                const refreshed = await livewireComponent.$wire.requestNostrChallenge();
                if (typeof refreshed === 'string' && refreshed !== '') {
                    return refreshed;
                }
            } catch (error) {
                console.error('requestNostrChallenge failed:', error);
            }
        }

        return null;
    },

    /**
     * The app origin the server expects in the login event's `u` tag
     * (NostrAuth::loginUrl(), rendered by the auth-button component).
     * Falls back to the page origin, which matches whenever APP_URL does.
     */
    resolveLoginUrl() {
        const fromDataset = this.$root?.dataset?.nostrLoginUrl;
        if (typeof fromDataset === 'string' && fromDataset !== '') {
            return fromDataset;
        }

        return window.location.origin;
    },

    async openNostrLogin() {
        this.nostrLoginInProgress = true;

        try {
            if (!window.nostr || typeof window.nostr.signEvent !== 'function') {
                this.showAuthError('Keine Nostr-Erweiterung gefunden. Bitte installiere einen Nostr-Signer (z.B. nos2x, Alby).');
                this.nostrLoginInProgress = false;
                return;
            }

            const challenge = await this.resolveChallenge();
            if (!challenge) {
                this.showAuthError('Login-Challenge fehlt. Bitte lade die Seite neu und versuche es erneut.');
                this.nostrLoginInProgress = false;
                return;
            }

            const pubkey = await window.nostr.getPublicKey();

            // The `u` tag binds the event to this app. Kind 22242 with a
            // `challenge` tag is also NIP-42 relay AUTH; the server refuses
            // any login event without `u` or with a `relay` tag, so an
            // answer given to a relay can never be replayed here as a login.
            const event = {
                kind: 22242,
                created_at: Math.floor(Date.now() / 1000),
                tags: [['challenge', challenge], ['u', this.resolveLoginUrl()]],
                content: '',
            };

            let signedEvent;
            try {
                signedEvent = await window.nostr.signEvent(event);
            } catch (error) {
                console.error('Nostr signEvent failed:', error);
                this.showAuthError('Signatur abgebrochen oder fehlgeschlagen. Bitte versuche es erneut.');
                this.nostrLoginInProgress = false;
                return;
            }

            // Some Nostr extensions return objects wrapped in extension-specific
            // proxies (e.g. cloneInto results) that Livewire cannot serialize.
            // Round-trip through JSON to guarantee a plain, cloneable object.
            let plainSignedEvent;
            try {
                plainSignedEvent = JSON.parse(JSON.stringify(signedEvent));
            } catch (error) {
                console.error('Nostr signedEvent serialization failed:', error);
                this.showAuthError('Wallet-Signatur konnte nicht verarbeitet werden. Bitte versuche eine andere Erweiterung.');
                this.nostrLoginInProgress = false;
                return;
            }

            // Pre-fetch the profile so it lands in the Alpine store before the
            // reload completes. Non-critical: failures are logged but ignored.
            try {
                const response = await fetch('/api/nostr/profile/' + pubkey);
                if (response.ok) {
                    const data = await response.json();
                    Alpine.store('nostr', {user: data});
                }
            } catch (error) {
                console.warn('Profile prefetch failed:', error);
            }

            // Leave nostrLoginInProgress = true: the auth-button listener
            // will trigger a full page reload on success.
            this.$dispatch('nostrLoggedIn', {signedEvent: plainSignedEvent});
        } catch (error) {
            console.error('openNostrLogin unexpected error:', error);
            this.showAuthError('Authentifizierung fehlgeschlagen. Bitte versuche es erneut.');
            this.nostrLoginInProgress = false;
        }
    },

    showAuthError(message) {
        if (window.Flux && window.Flux.toast) {
            window.Flux.toast({
                heading: 'Authentifizierung fehlgeschlagen',
                text: message,
                variant: 'danger',
                duration: 8000,
            });
        } else {
            console.error(message);
        }
    },
});
