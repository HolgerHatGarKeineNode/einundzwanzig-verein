<?php

use Symfony\Component\Finder\Finder;

/*
 * Jury run 1, L2: `nostrApp.js` was registered as an Alpine component that
 * entangled a Livewire property and handed whatever the server put there to
 * window.nostr.signEvent, unseen by the member. No view used it. A
 * registration nobody renders is still in the bundle, one `x-data` away from
 * being used. This keeps every registered component tied to a view.
 *
 * KNOWN_UNRENDERED lists the registrations found unrendered in the same
 * sweep and left alone because they were outside that package. Removing one
 * means deleting it here too; adding a name here needs a reason. `nostrZap`
 * was the second one and was removed on 2026-11-24 (page optimisation, P6).
 */
const KNOWN_UNRENDERED = ['nostrDefault'];

it('registers only Alpine components that some view renders', function () {
    $root = dirname(__DIR__, 2);
    preg_match_all("/Alpine\\.data\\('([A-Za-z0-9_]+)'/", file_get_contents($root.'/resources/js/app.js'), $matches);
    $registered = $matches[1];

    $views = '';
    foreach (Finder::create()->files()->in([$root.'/resources/views', $root.'/vendor/einundzwanzig'])->name('*.blade.php') as $file) {
        $views .= $file->getContents();
    }

    $unrendered = array_values(array_filter(
        $registered,
        fn (string $name): bool => preg_match('/x-data="'.preg_quote($name, '/').'[\s"(]/', $views) !== 1,
    ));

    expect($registered)->not->toBeEmpty()
        ->and($unrendered)->toBe(KNOWN_UNRENDERED);
});
