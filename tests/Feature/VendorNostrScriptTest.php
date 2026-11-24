<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;

/*
 * Page optimisation P15: window.nostr.js (187 KB) is loaded with `defer`, so
 * it no longer blocks parsing. The nsec guard depends on running first: its
 * inline script must come before the vendor tag in the document (see
 * resources/views/partials/wnj-nsec-guard.blade.php). Its behaviour against
 * the real vendor file is covered by tests/e2e/wnj-nsec-guard.spec.js; this
 * test only pins the markup order and the attribute.
 */
it('loads window.nostr.js deferred and after the nsec guard', function () {
    $this->withoutVite();
    NostrAuth::login(EinundzwanzigPleb::factory()->active()->create()->pubkey);

    $this->get('/association/profile')
        ->assertOk()
        ->assertSeeInOrder([
            "const POINTER = 'wnj:bunkerPointer';",
            '<script src="'.asset('dist/window.nostr.min.js.js').'" defer></script>',
        ], false);
});
