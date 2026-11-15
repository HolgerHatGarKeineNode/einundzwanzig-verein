import { signerKind, withTimeout } from './nostrSigner.js'

// A bunker on a phone needs the member to switch apps and approve; two
// minutes is generous for that and still ends a wait that would never end.
const LOGIN_SIGNER_TIMEOUT_MS = 120000

export default () => ({
    // Toggled while window.nostr.signEvent is awaiting the wallet so the
    // button disables itself, the loading overlay renders, and downstream
    // wire:poll consumers can pause if needed. Stays true through the
    // backend round-trip — the auth-button component reloads the page on
    // success, so resetting the flag here would just flicker the overlay
    // away while navigation is already underway.
    nostrLoginInProgress: false,

    // Counts login attempts. An answer that arrives for an attempt that was
    // cancelled or timed out is dropped instead of logging the member in
    // behind their back.
    nostrLoginAttempt: 0,

    // The control that started the login, to get the focus back when the
    // overlay closes without a login (cancel, timeout, refusal).
    nostrLoginOpener: null,

    cancelNostrLogin() {
        this.nostrLoginAttempt++
        this.endNostrLogin()
    },

    endNostrLogin() {
        this.nostrLoginInProgress = false
        const opener = this.nostrLoginOpener
        this.nostrLoginOpener = null
        if (opener && opener.isConnected && typeof opener.focus === 'function') {
            this.$nextTick(() => opener.focus())
        }
    },

    /**
     * No usable signer on this device. Calling window.nostr now would open the
     * vendor's English dialog with its `nsec1…` field as the first thing a
     * member meets. A page that carries the German help (data-signer-help)
     * shows it instead; elsewhere a German toast says what is missing.
     */
    showSignerHelp() {
        const help = document.querySelector('[data-signer-help]')

        if (help) {
            window.dispatchEvent(new CustomEvent('nostr-signer-missing'))
            return
        }

        this.showAuthError('Auf diesem Gerät ist kein Nostr-Signer eingerichtet. Installiere eine Browser-Erweiterung wie Alby oder nos2x und lade die Seite neu.')
    },

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

    async openNostrLogin(options = {}) {
        // `force`: the member chose "Signer-App verbinden" in the German help
        // and expects the connect dialog of window.nostr.js.
        if (!options.force && signerKind() === 'none') {
            this.showSignerHelp();
            return;
        }

        if (!window.nostr || typeof window.nostr.signEvent !== 'function') {
            this.showSignerHelp();
            return;
        }

        const attempt = ++this.nostrLoginAttempt;
        const isCurrent = () => attempt === this.nostrLoginAttempt;
        this.nostrLoginOpener = document.activeElement;
        this.nostrLoginInProgress = true;

        try {

            const challenge = await this.resolveChallenge();
            if (!challenge) {
                this.showAuthError('Login-Challenge fehlt. Bitte lade die Seite neu und versuche es erneut.');
                this.endNostrLogin();
                return;
            }

            const pubkey = await withTimeout(window.nostr.getPublicKey(), LOGIN_SIGNER_TIMEOUT_MS);
            if (!isCurrent()) {
                return;
            }

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
                signedEvent = await withTimeout(window.nostr.signEvent(event), LOGIN_SIGNER_TIMEOUT_MS);
            } catch (error) {
                if (!isCurrent()) {
                    return;
                }
                this.showAuthError(error?.message === 'timeout'
                    ? 'Dein Signer hat nicht geantwortet. Öffne ihn, bestätige dort und versuche es noch einmal.'
                    : 'Signatur abgebrochen oder fehlgeschlagen. Bitte versuche es erneut.');
                this.endNostrLogin();
                return;
            }

            if (!isCurrent()) {
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
                this.endNostrLogin();
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
            if (!isCurrent()) {
                return;
            }

            // `location`: only the auth-button that started this login answers.
            this.$dispatch('nostrLoggedIn', {signedEvent: plainSignedEvent, location: this.$root?.dataset?.location ?? null});
        } catch (error) {
            if (!isCurrent()) {
                return;
            }
            this.showAuthError(error?.message === 'timeout'
                ? 'Dein Signer hat nicht geantwortet. Öffne ihn, bestätige dort und versuche es noch einmal.'
                : 'Authentifizierung fehlgeschlagen. Bitte versuche es erneut.');
            this.endNostrLogin();
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
