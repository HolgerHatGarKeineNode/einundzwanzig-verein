<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;

require_once __DIR__.'/Services/Election/helpers.php';

/**
 * The queue dashboard shows job payloads (member ids, message ids): only the
 * current board may open it outside the local environment.
 */
it('opens the Horizon dashboard for the board only', function () {
    app()->detectEnvironment(fn (): string => 'production');

    $this->get('/horizon')->assertForbidden();

    NostrAuth::login(EinundzwanzigPleb::factory()->active()->create()->pubkey);
    $this->get('/horizon')->assertForbidden();

    [$board] = electionBoard(1);
    NostrAuth::login($board->pubkey);
    $this->get('/horizon')->assertOk();
});
