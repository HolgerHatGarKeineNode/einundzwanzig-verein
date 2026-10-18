<?php

/**
 * Verlinkt vendor/einundzwanzig/group auf ein danebenliegendes Package-Repo.
 *
 * Gegenstueck zum bedingten Vite-Alias (siehe vite.config.js): Liegt das Repo
 * einundzwanzig-group als Nachbarverzeichnis, arbeiten wir lokal direkt dagegen
 * — eine Aenderung an dessen Blade-Views wirkt dann sofort, ohne Commit, Push
 * und composer update.
 *
 * Bewusst als Skript und NICHT als path-Repository in der composer.json: Ein
 * path-Repo, dessen url nicht existiert, laesst `composer install` mit
 * "The `url` supplied for the path repository does not exist" hart abbrechen —
 * auf dem Server, wo es kein Nachbarverzeichnis gibt, waere das Deployment
 * daran gescheitert. Dieses Skript ist dort schlicht ein No-op.
 *
 * Laeuft automatisch nach composer install/update.
 *
 * OPT-IN since 2026-10-18: only with GROUP_PACKAGE_LOCAL=1. The neighbour
 * checkout moves ahead with unreleased work for its own child projects; linking
 * it by default made this repo's test suite red against a version that
 * composer.lock never pinned. Without the variable, vendor/ follows the lock.
 */
$link = dirname(__DIR__).'/vendor/einundzwanzig/group';

if (getenv('GROUP_PACKAGE_LOCAL') !== '1') {
    if (is_link($link)) {
        unlink($link);
        fwrite(STDOUT, "\033[33m→ einundzwanzig/group: local link removed — run `composer install` once more to restore the pinned version\033[0m\n");
    }

    exit(0);
}

$target = realpath(__DIR__.'/../../einundzwanzig-group/packages/einundzwanzig-group');

if ($target === false || ! is_file($target.'/composer.json')) {
    // Kein Nachbar-Repo: Es bleibt bei der in composer.lock gepinnten Version.
    exit(0);
}

if (! is_dir(dirname($link))) {
    // Package noch nicht installiert — nichts zu verlinken.
    exit(0);
}

if (is_link($link)) {
    if (readlink($link) === $target) {
        exit(0);
    }
    unlink($link);
} elseif (is_dir($link)) {
    // Die installierte Kopie weicht dem Symlink. Sie kommt bei jedem
    // composer install zurueck, ein Verlust entsteht also nicht.
    $rm = static function (string $dir) use (&$rm): void {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $rm($path) : unlink($path);
        }
        rmdir($dir);
    };
    $rm($link);
}

if (! symlink($target, $link)) {
    fwrite(STDERR, "  Symlink auf das lokale Package-Repo fehlgeschlagen.\n");
    exit(0);
}

fwrite(STDOUT, "\033[33m→ einundzwanzig/group: lokales Nachbar-Repo verlinkt (nicht die Version aus composer.lock)\033[0m\n");
