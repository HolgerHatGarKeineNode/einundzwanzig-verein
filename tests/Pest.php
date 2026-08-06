<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Test Impact Analysis
|--------------------------------------------------------------------------
|
| TIA replays cached results for tests unaffected by the current change and
| re-runs the rest. Deliberately NOT calling ->locally() or ->always():
| both auto-enable TIA for every run (locally() only skips it in CI —
| see Environment::LOCAL check in vendor/pestphp/pest/src/Plugins/Tia.php,
| handleArguments()/isEnabledForRun()), so either one would make even a
| bare `./vendor/bin/pest --compact` run in TIA mode. TIA stays strictly
| opt-in via the explicit `--tia` CLI flag. "filtered()" actually narrows
| the selection instead of only skipping replay — applies whenever --tia
| is passed.
|
| Most PHP source changes are resolved precisely by TIA's own coverage
| graph — no watch() pattern is needed or wanted for them, since a pattern
| can only broaden the selection back towards "whole directory", undoing
| the graph's precision. Verified empirically (--tia --fresh, then a real
| behavioural edit + `./vendor/bin/pest --tia`, reverted after each probe):
| app/Livewire, app/Policies, app/Http/Controllers/Nostr and
| config/einundzwanzig/config.php are all resolved to the exact dependent
| test file(s) by the graph alone.
|
| The two exceptions below exist because some source files never execute a
| single line during any test run (e.g. an Eloquent relation method nobody
| calls directly, only via the query builder) and so never enter the
| coverage graph. Unlike app/Policies, app/Providers, etc., "app/Models"
| and "app/Auth" are not covered by Pest's own sibling-directory fallback
| (see WatchDefaults and Graph::usesSiblingHeuristicForUnknownPhp() in
| vendor/pestphp/pest), so an edit to such a file would otherwise select
| zero affected tests. Confirmed live: a behavioural edit to
| app/Models/Vote.php::einundzwanzigPleb() and to
| app/Auth/NostrUserProvider.php::retrieveById() each produced an EMPTY
| affected set under --tia before these patterns were added.
|
| The target is the whole test path, not a single subdirectory: a given
| Model or Auth class is exercised from wherever it happens to be used
| (e.g. App\Models\Vote is asserted against from Policies, Livewire and
| rate-limiting tests alike), so a narrower target would just trade one
| blind spot for another.
|
| No ->baselined() on purpose. It fetches a dependency graph recorded by CI
| so a team can share one instead of each machine building its own — and it
| is wired specifically to GitHub Actions: BaselineSync expects a workflow
| named tia-baseline.yml producing an artifact "pest-tia-baseline" and pulls
| it via the `gh` CLI. This repo has no CI at all (no .github/, deployment
| runs through Forge), and the locally recorded graph already delivers the
| full ~22-25x replay speedup. Revisit only if CI is introduced.
|
| One trap worth knowing: TIA does NOT follow the symlinked sibling package
| vendor/einundzwanzig/group. An edit over in einundzwanzig-group selects
| zero affected tests here, so --tia would report a green replay that never
| exercised the change. Cause is a symlink/realpath mismatch between PHPUnit
| and Pest that no config in this repo fixes; use --no-tia (or a full run)
| while working on that package.
|
*/

pest()->tia()
    ->filtered()
    ->watch([
        'app/Models/**/*.php' => 'tests',
        'app/Auth/**/*.php' => 'tests',
    ]);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
| toBeNostrHexKey(): a NIP-01 pubkey/id/sig must be exactly 64 lowercase hex
| characters. toBeHexadecimal() alone accepts uppercase A-F (it wraps
| ctype_xdigit()), which NIP-01 forbids — the trailing toMatch() keeps the
| lowercase-only guarantee that toHaveLength()+toBeHexadecimal() would lose.
*/
expect()->extend('toBeNostrHexKey', function () {
    return $this->toHaveLength(64)->toBeHexadecimal()->toMatch('/^[0-9a-f]+$/');
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Build a NIP-42-style kind-22242 login event signed with a freshly generated
 * keypair. Returns the signed event as the plain array that the frontend
 * dispatches to Livewire (post-JSON round-trip), plus the pubkey for assertions.
 *
 * Lives here rather than in a single test file because more than one suite
 * needs a genuinely signed login: the auth tests themselves, and every test
 * that has to reach the state "logged in the way a real browser does".
 *
 * Pass `$privkey` to sign a second, different challenge with the same identity —
 * that is what a returning member's browser does on the next login.
 *
 * @return array{event: array<string, mixed>, pubkey: string, privkey: string}
 */
function makeSignedLoginEvent(string $challenge, ?int $createdAt = null, ?string $privkey = null): array
{
    $key = new Key;
    $privkey ??= $key->generatePrivateKey();
    $pubkey = $key->getPublicKey($privkey);

    $event = new Event;
    $event->setKind(22242);
    $event->setCreatedAt($createdAt ?? time());
    $event->setTags([['challenge', $challenge]]);
    $event->setContent('');

    (new Sign)->signEvent($event, $privkey);

    $array = $event->toArray();

    // Match the shape produced by JSON.parse(JSON.stringify(signedEvent)) in
    // nostrLogin.js — plain arrays, integer kind/created_at, string sig/id.
    return [
        'event' => [
            'id' => $array['id'],
            'pubkey' => $array['pubkey'],
            'created_at' => $array['created_at'],
            'kind' => $array['kind'],
            'tags' => $array['tags'],
            'content' => $array['content'],
            'sig' => $array['sig'],
        ],
        'pubkey' => $pubkey,
        'privkey' => $privkey,
    ];
}

function something()
{
    // ..
}
