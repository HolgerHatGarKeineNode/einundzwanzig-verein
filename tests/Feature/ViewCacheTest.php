<?php

/*
 * The deploy script runs `php artisan optimize`, and its view:cache step compiles every
 * registered view path — the group package's included, used here or not. On 2026-10-22
 * the release died on "Unable to locate a class or view for component [flux::icon.pin]"
 * (the package's own Flux icon path was never registered by GroupPackageServiceProvider),
 * and behind it the package's command palette broke Livewire's morph-marker compiler
 * with "regular expression is too large". Neither showed up in a single feature test.
 */

afterEach(function () {
    $this->artisan('view:clear');
});

it('compiles every registered view like the deploy does', function () {
    $this->artisan('view:cache')->assertSuccessful();
});
