<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;

/*
 * Successor of ElectionRoutesRemovedTest: the old election pages were removed
 * on 2026-08-07, the member page of the new election lives at
 * /association/election again.
 */

it('serves the member election page under its name', function () {
    expect(route('association.election', absolute: false))->toBe('/association/election');

    NostrAuth::login(EinundzwanzigPleb::factory()->active()->create()->pubkey);

    $this->get('/association/election')->assertOk()->assertSee('Gerade steht keine Wahl an');
});

it('answers a guest with the members-only notice, not with an error', function () {
    $this->get('/association/election')->assertOk()->assertSee('Die Wahl ist nur für Mitglieder sichtbar');
});

it('keeps the pages of the old election removed', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    'old show page' => '/association/election/2024',
]);

it('lists the election page in the member navigation', function () {
    NostrAuth::login(EinundzwanzigPleb::factory()->active()->create()->pubkey);

    $this->get('/association/profile')->assertOk()->assertSee(route('association.election'), false)->assertSee('Wahl');
});
