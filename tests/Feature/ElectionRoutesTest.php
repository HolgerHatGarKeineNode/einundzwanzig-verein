<?php

use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Support\NostrAuth;
use Illuminate\Routing\Route;

require_once __DIR__.'/Services/Election/helpers.php';

/*
 * Successor of ElectionRoutesRemovedTest: the old election pages were removed
 * on 2026-08-07, the member page of the new election lives at
 * /association/election again.
 *
 * Commit f14e637 deleted ElectionRoutesRemovedTest.php and added this file
 * instead of renaming it, so `git log --follow` starts here. What became of
 * each assertion of the old file (git show f14e637^:tests/Feature/ElectionRoutesRemovedTest.php):
 *
 *   404 '/association/election'             replaced on purpose: the member page
 *                                           ("serves the member election page",
 *                                           "answers a guest with the notice")
 *   404 '/association/election/2024'        kept ("old show page")
 *   404 '/association/election/admin/2024'  kept ("old admin page"); dropped in
 *                                           f14e637, restored 2026-11-16
 *   no route name containing "election"     replaced on purpose by the exact
 *                                           inventory below, same filter;
 *                                           restored 2026-11-16
 */

/**
 * Every route name containing "election" — the filter of the old test.
 *
 * @return list<string>
 */
function electionRouteNames(): array
{
    return collect(app('router')->getRoutes())
        ->map(fn (Route $route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => $name !== null && str_contains($name, 'election'))
        ->sort()
        ->values()
        ->all();
}

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
    'old admin page' => '/association/election/admin/2024',
]);

it('registers exactly these election routes', function () {
    expect(electionRouteNames())->toBe([
        'association.election',
        'association.election.admin',
        'association.election.admin.protocol',
        'association.election.admin.screen',
    ]);
});

/*
 * Every election admin route of the inventory, so a new one is held to the
 * board check by being registered (blickwinkel-tests M16).
 */
it('refuses every election admin route to a member who is not on the board', function () {
    $election = Election::factory()->create();
    $admin = array_values(array_filter(electionRouteNames(), fn (string $name): bool => str_starts_with($name, 'association.election.admin')));

    NostrAuth::login(electionVoter()->pubkey);

    foreach ($admin as $name) {
        $url = route($name, app('router')->getRoutes()->getByName($name)->parameterNames() === [] ? [] : ['election' => $election]);

        expect($this->get($url)->status())->toBe(403, "{$name} ({$url})");
    }

    expect($admin)->toHaveCount(3);
});

it('lists the election page in the member navigation', function () {
    NostrAuth::login(EinundzwanzigPleb::factory()->active()->create()->pubkey);

    $this->get('/association/profile')->assertOk()->assertSee(route('association.election'), false)->assertSee('Wahl');
});
