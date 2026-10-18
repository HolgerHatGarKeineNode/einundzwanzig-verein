<?php

/*
 * The suite runs against the group package version composer.lock pins, unless
 * someone opts in to the neighbour checkout with GROUP_PACKAGE_LOCAL=1.
 *
 * Before 2026-10-18 the link was automatic. The neighbour checkout was 49
 * commits ahead with unreleased views (`<flux:icon.pin>`, new `group.*`
 * routes), and ten ProjectSupportTest cases failed for code production never
 * ran. This test names the cause directly instead of leaving it to be
 * reverse-engineered from a missing Flux icon.
 */

it('uses the pinned group package unless the local checkout is opted in', function () {
    if (getenv('GROUP_PACKAGE_LOCAL') === '1') {
        $this->markTestSkipped('GROUP_PACKAGE_LOCAL=1: developing against the neighbour checkout on purpose.');
    }

    $path = base_path('vendor/einundzwanzig/group');

    expect(is_link($path))->toBeFalse(
        'vendor/einundzwanzig/group is a symlink to '.@readlink($path).'. Run `composer install` twice to restore the pinned version, then `php artisan view:clear`.'
    );
});
