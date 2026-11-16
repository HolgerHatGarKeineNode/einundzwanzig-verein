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
         warning names the way that works (bunker link or QR code).

     A `bunker://` value passes untouched. Must stay BEFORE the vendor's
     <script> tag. Pinned by tests/e2e/wnj-nsec-guard.spec.js. --}}
<script>
    (() => {
        const POINTER = 'wnj:bunkerPointer';
        const HASH = '#nostr-login=';
        const WARNING = 'Kein nsec hier eintragen: Er bliebe im Browser gespeichert. Füge den bunker://-Link aus deiner Signer-App ein oder scanne den QR-Code.';
        const isNsec = (value) => typeof value === 'string' && value.trim().toLowerCase().startsWith('nsec1');

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
        };

        const refuse = (event, input) => {
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

            for (const type of ['input', 'keydown', 'submit']) {
                root.addEventListener(type, (event) => {
                    if (type === 'keydown' && event.key !== 'Enter') {
                        return;
                    }
                    const input = fieldOf(event.target);
                    if (input && isNsec(input.value)) {
                        refuse(event, input);
                    }
                }, true);
            }
        };

        // The vendor script follows this one; it has run by DOMContentLoaded.
        document.addEventListener('DOMContentLoaded', attach);
        document.addEventListener('livewire:navigated', attach);
        attach();
    })();
</script>
