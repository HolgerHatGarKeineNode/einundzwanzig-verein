<?php

/**
 * Broadcasting was removed on 2026-08-07 because it had never been in service:
 * production carried neither a REVERB_ nor a BROADCAST_ variable, no Reverb
 * daemon ran, the Echo import in `resources/js/bootstrap.js` was commented out,
 * and the built bundle contained neither an app key nor `laravel-echo`. What
 * remained was configuration surface nobody maintained and every audit had to
 * re-check.
 *
 * This file replaces `tests/Feature/ReverbConfigTest.php`, which guarded the
 * hardening of `config/reverb.php` and became meaningless with that file. The
 * guard is now one level up: not "are the Reverb credentials clean" but "is the
 * stack still gone". These are source-level assertions on purpose — a dependency
 * or a dev-script entry that creeps back in is invisible to a runtime check.
 */
$sourceDirectories = ['app', 'bootstrap', 'config', 'resources', 'routes', 'tests'];

/**
 * Every file of the repository's own source, vendored and built artefacts left out.
 *
 * @return array<int, string>
 */
function repositorySourceFiles(array $directories): array
{
    $files = [];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();

            if (str_contains($path, '/node_modules/') || str_contains($path, '/vendor/')) {
                continue;
            }

            $files[] = $path;
        }
    }

    return $files;
}

it('no longer references the broadcast helper that never existed', function () use ($sourceDirectories) {
    /*
     * Assembled at runtime so this very assertion does not become the last
     * occurrence of the string it forbids.
     */
    $needle = implode('\\', ['App', 'Support', 'Broadcast']);

    $offenders = array_values(array_filter(
        repositorySourceFiles($sourceDirectories),
        fn (string $path): bool => str_contains((string) file_get_contents($path), $needle)
    ));

    expect($offenders)->toBeEmpty();
});

it('binds no Livewire component to an Echo channel any more', function () use ($sourceDirectories) {
    /*
     * `echo:votes,.newVote` was the client half of the same dead feature: it
     * waited for the `newVote` event on the `votes` channel that the removed
     * broadcast call was meant to emit. It could never fire — the Echo import
     * in `resources/js/bootstrap.js` has been commented out since 772853d, so
     * `window.Echo` never existed in the first place.
     */
    // Assembled at runtime for the same reason as above: a literal needle would
    // make this assertion its own last offender.
    $needle = chr(39).'echo'.':';

    $offenders = array_values(array_filter(
        repositorySourceFiles($sourceDirectories),
        fn (string $path): bool => str_contains((string) file_get_contents($path), $needle)
    ));

    expect($offenders)->toBeEmpty();
});

it('carries no Reverb, Pusher or Echo dependency any more', function () {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $package = json_decode((string) file_get_contents(base_path('package.json')), true);

    $dependencies = array_keys(array_merge(
        $composer['require'] ?? [],
        $composer['require-dev'] ?? [],
        $package['dependencies'] ?? [],
        $package['devDependencies'] ?? [],
    ));

    expect($dependencies)
        ->not->toContain('laravel/reverb')
        ->not->toContain('pusher/pusher-php-server')
        ->not->toContain('laravel-echo')
        ->not->toContain('pusher-js');
});

it('does not hang the dev environment on a Reverb process that can no longer start', function () {
    /*
     * The `dev` script chains its processes with `--kill-others`. As long as
     * `php artisan reverb:start` was part of that chain, its immediate death
     * after the package removal would have torn down serve, queue, pail and
     * vite along with it.
     */
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
    $dev = implode(' ', $composer['scripts']['dev'] ?? []);

    expect($dev)
        ->not->toContain('reverb')
        ->and($dev)->toContain('--kill-others');
});
