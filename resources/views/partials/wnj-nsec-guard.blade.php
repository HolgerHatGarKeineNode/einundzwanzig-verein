{{-- Keeps a member's nsec out of window.nostr.js (public/dist/window.nostr.min.js.js, 0.7.0).

     The vendor's connect field takes `bunker://…` or `nsec1…`. An nsec typed
     there is kept in plaintext in localStorage['wnj:bunkerPointer'] and is
     reactivated on every load; a link with `#nostr-login=nsec1…` does the same
     without any typing (jury run 2, M-2). The vendor has no option to turn the
     nsec path off, so this guard sits around it, without touching the file:

       - before the vendor runs: a stored nsec pointer is removed, and an nsec
         in the `#nostr-login=` hash is stripped from the URL;
       - on the vendor's open shadow root, in the capture phase: an input,
         Enter or submit whose value starts with `nsec1` is stopped before the
         vendor's own listeners see it, the field is emptied and a German
         warning names the way that works (bunker link or QR code);
       - from then on the field is tainted: every further value is refused
         the same way until it is a whole `bunker://` link or NIP-05 address
         (pasted or filled in). Typed key by key, an nsec reaches `nsec1`
         first and then arrives one character at a time on an empty field;
         without the taint all 58 characters after the prefix would pass
         (gate j2b, D2). Backspace does not lift it: someone correcting a typo
         in the middle of the key would hand the rest of it to the vendor.
         A new field (dialog closed and reopened, or a reload) starts clean;
       - the vendor's panel is anchored at the bottom and grows upward, past
         the top of the viewport on short screens. Its height is capped to the
         room above its bottom edge and its body scrolls, so the field and the
         warning stay on screen (gate j2b, D1).

     A `bunker://` value passes untouched. Must stay BEFORE the vendor's
     <script> tag. Pinned by tests/e2e/wnj-nsec-guard.spec.js. --}}
<script>
    (() => {
        const POINTER = 'wnj:bunkerPointer';
        const HASH = '#nostr-login=';
        const WARNING = 'Kein nsec hier eintragen: Er bliebe im Browser gespeichert. Füge den bunker://-Link aus deiner Signer-App ein oder scanne den QR-Code.';
        const isNsec = (value) => typeof value === 'string' && value.trim().toLowerCase().startsWith('nsec1');
        // What the vendor itself accepts besides an nsec: a bunker link, or a
        // NIP-05 address (its own pattern, window.nostr.js `Yi`).
        const NIP05 = /^(?:[\w.+-]+@)?[\w-]+(\.[\w-]+)+$/;
        const isAccepted = (value) => {
            const trimmed = value.trim();

            return trimmed.toLowerCase().startsWith('bunker://') || NIP05.test(trimmed);
        };
        const tainted = new WeakSet();
        const PANEL = '.draggable > .animate-show';
        const GAP = 20;

        try {
            if (isNsec(window.localStorage.getItem(POINTER))) {
                window.localStorage.removeItem(POINTER);
            }
        } catch (error) {
            // No storage, nothing stored.
        }

        if (window.location.hash.startsWith(HASH)) {
            let value = window.location.hash.slice(HASH.length);
            try {
                value = decodeURIComponent(value);
            } catch (error) {
                // Not decodable: judged as it stands.
            }
            if (isNsec(value)) {
                history.replaceState(history.state, '', window.location.pathname + window.location.search);
            }
        }

        // Caps the vendor's panel to the room between its anchored edge and
        // the far edge of the viewport; the stylesheet below makes it scroll.
        const fit = (node) => {
            const panel = node?.closest?.(PANEL);
            const frame = panel?.parentElement;
            if (!panel || !frame) {
                return;
            }
            const rect = frame.getBoundingClientRect();
            const room = frame.style.top !== '' ? window.innerHeight - rect.top : rect.bottom;
            panel.style.setProperty('--portal-wnj-room', `${Math.max(0, Math.floor(room - GAP))}px`);
        };

        const warn = (input) => {
            let note = input.parentNode?.querySelector('[data-portal-nsec-warning]');
            if (!note) {
                note = document.createElement('p');
                note.setAttribute('data-portal-nsec-warning', '');
                note.setAttribute('role', 'alert');
                note.setAttribute('lang', 'de');
                note.style.cssText = 'margin:.5rem 0 0;padding:.5rem .75rem;border-radius:.25rem;background:#fff;color:#991b1b;font-size:.95rem;line-height:1.35;font-weight:600;text-align:left';
                note.textContent = WARNING;
                input.insertAdjacentElement('afterend', note);
            }
            fit(input);
            note.scrollIntoView({ block: 'nearest' });
        };

        const refuse = (event, input) => {
            tainted.add(input);
            event.preventDefault();
            event.stopImmediatePropagation();
            input.value = '';
            // The vendor mirrors the field through its own input listener;
            // an empty value there keeps it from holding the key.
            input.dispatchEvent(new Event('input', { bubbles: true }));
            warn(input);
            input.focus();
        };

        const fieldOf = (target) => {
            if (target instanceof HTMLInputElement) {
                return target;
            }

            return target instanceof HTMLFormElement ? target.querySelector('input') : null;
        };

        const guarded = new WeakSet();

        const attach = () => {
            const host = Array.from(document.body?.children ?? []).find((element) => element.shadowRoot?.getElementById('wnj'));
            const root = host?.shadowRoot;
            if (!root || guarded.has(root)) {
                return;
            }
            guarded.add(root);

            const style = document.createElement('style');
            style.setAttribute('data-portal-nsec-guard', '');
            style.textContent = `${PANEL} { max-height: var(--portal-wnj-room, calc(100dvh - ${2 * GAP}px)); overflow-y: auto; overscroll-behavior: contain; }`;
            root.appendChild(style);

            // An empty value always passes: it is what refuse() hands on.
            const refused = (input) => {
                const value = input.value;
                if (value === '') {
                    return false;
                }
                if (isNsec(value)) {
                    return true;
                }
                if (!tainted.has(input)) {
                    return false;
                }
                if (isAccepted(value)) {
                    tainted.delete(input);

                    return false;
                }

                return true;
            };

            for (const type of ['input', 'keydown', 'submit']) {
                root.addEventListener(type, (event) => {
                    if (type === 'keydown' && event.key !== 'Enter') {
                        return;
                    }
                    const input = fieldOf(event.target);
                    if (input && refused(input)) {
                        refuse(event, input);
                    }
                }, true);
            }

            // The vendor focuses its field when the dialog opens.
            root.addEventListener('focusin', (event) => fit(event.target), true);
            window.addEventListener('resize', () => fit(root.querySelector(`${PANEL} input`)));
        };

        // The vendor script follows this one; it has run by DOMContentLoaded.
        document.addEventListener('DOMContentLoaded', attach);
        document.addEventListener('livewire:navigated', attach);
        attach();
    })();
</script>
