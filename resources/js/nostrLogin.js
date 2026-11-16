import { signerKind, withTimeout } from './nostrSigner.js'

// A bunker on a phone needs the member to switch apps and approve; two
// minutes is generous for that and still ends a wait that would never end.
const LOGIN_SIGNER_TIMEOUT_MS = 120000

// Fetching a fresh challenge is one Livewire roundtrip. A request that never
// settles (a 419 confirm left open, a dead connection) must not hold the
// overlay up.
const CHALLENGE_REQUEST_TIMEOUT_MS = 20000

// Once the signed event is handed over, the server either reloads the page
// (accepted) or answers `nostr-login-refused` (refused). A request that fails
// on the way gives neither; this ends the wait instead.
const LOGIN_SERVER_TIMEOUT_MS = 30000

// NostrAuth::issueChallenge(): 32 random bytes, lowercase hex.
const CHALLENGE_PATTERN = /^[0-9a-f]{64}$/

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

    // The attempt whose signed event is with the server, and the timer that
    // ends the wait for its answer. Only that attempt may be ended by a
    // refusal; a cancelled or newer attempt is left alone.
    nostrLoginAwaitingServer: null,
    nostrLoginServerTimer: null,

    cancelNostrLogin() {
        this.nostrLoginAttempt++
        this.endNostrLogin()
    },

    endNostrLogin() {
        this.nostrLoginInProgress = false
        this.nostrLoginAwaitingServer = null
        clearTimeout(this.nostrLoginServerTimer)
        this.nostrLoginServerTimer = null
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
     * A fresh login challenge from the server, for every attempt.
     *
     * The challenge rendered into the page (data-nostr-challenge) is never
     * used: the server refuses it 300 s after the page was rendered
     * (NostrAuth::CHALLENGE_TTL_SECONDS), and a guest tab is routinely open
     * longer than that, for example the election page opened before the call.
     * Reading the rendered value first left such a tab unable to log in
     * without a reload (jury run 2, H-1). One roundtrip per attempt is the
     * price; the server keeps every other rule (single use, `u` binding, no
     * `relay` tag, the created_at window).
     *
     * @returns {Promise<string|null>} null when no challenge could be fetched
     */
    async resolveChallenge() {
        const wire = this.$root?.closest('[wire\\:id]')?.__livewire?.$wire;
        if (!wire || typeof wire.requestNostrChallenge !== 'function') {
            return null;
        }

        try {
            const fresh = await withTimeout(wire.requestNostrChallenge(), CHALLENGE_REQUEST_TIMEOUT_MS);

            return typeof fresh === 'string' && CHALLENGE_PATTERN.test(fresh) ? fresh : null;
        } catch (error) {
            return null;
        }
    },

    /**
     * The server refused the signed login event (auth-button
     * handleNostrLoggedIn dispatches `nostr-login-refused` to its own root).
     * Livewire answers that with HTTP 200, so without this the overlay stayed
     * up with no message and the member could only reload.
     */
    nostrLoginRefused() {
        if (!this.nostrLoginInProgress || this.nostrLoginAwaitingServer !== this.nostrLoginAttempt) {
            return;
        }

        this.nostrLoginAttempt++;
        this.showAuthError('Der Server hat die Anmeldung nicht angenommen. Versuche es noch einmal, dein Signer fragt dann neu. Klappt es wieder nicht, lade die Seite neu.');
        this.endNostrLogin();
    },

    /**
     * Wait for the server's answer to the dispatched login, but not forever:
     * a request that fails on the way (network, 5xx, rate limit) reloads
     * nothing and dispatches nothing.
     */
    awaitServerAnswer(attempt) {
        this.nostrLoginAwaitingServer = attempt;
        clearTimeout(this.nostrLoginServerTimer);

        const timer = setTimeout(() => {
            if (this.nostrLoginAwaitingServer !== attempt || attempt !== this.nostrLoginAttempt) {
                return;
            }
            this.nostrLoginAttempt++;
            this.showAuthError('Der Server hat nicht geantwortet. Prüfe deine Verbindung und versuche es noch einmal.');
            this.endNostrLogin();
        }, LOGIN_SERVER_TIMEOUT_MS);
        this.nostrLoginServerTimer = timer;

        // An accepted login reloads the page; the reload may take a moment
        // and must not be overtaken by this message.
        window.addEventListener('beforeunload', () => clearTimeout(timer), { once: true });
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

        // The opener is disabled while the overlay is up, so the focus would
        // fall to <body>. Move it into the dialog, onto its only control.
        // $nextTick waits for x-show's transition to make the overlay visible;
        // focusing before getPublicKey() keeps a vendor dialog that opens
        // afterwards free to take the focus itself.
        await this.$nextTick();
        if (isCurrent() && this.nostrLoginInProgress) {
            this.$refs.cancelLogin?.focus();
        }

        try {

            const pubkey = await withTimeout(window.nostr.getPublicKey(), LOGIN_SIGNER_TIMEOUT_MS);
            if (!isCurrent()) {
                return;
            }

            // Fetched after getPublicKey(): connecting a bunker can take a
            // while, and the challenge should be as young as possible when
            // the signer sees it.
            const challenge = await this.resolveChallenge();
            if (!isCurrent()) {
                return;
            }
            if (!challenge) {
                this.showAuthError('Die Anmeldung konnte nicht vorbereitet werden. Prüfe deine Verbindung und versuche es noch einmal.');
                this.endNostrLogin();
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
            // will trigger a full page reload on success, or answer
            // `nostr-login-refused` (nostrLoginRefused()).
            if (!isCurrent()) {
                return;
            }

            this.awaitServerAnswer(attempt);

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
