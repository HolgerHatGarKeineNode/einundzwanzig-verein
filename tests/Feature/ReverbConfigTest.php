<?php

/**
 * `config/reverb.php` trug bis 2026-08-06 App-Key und App-Secret als Literale in einem
 * oeffentlichen Repo, dazu `allowed_origins => ['*']`. Diese Datei ist der
 * Regressionsschutz: sie prueft die Config-QUELLE, nicht nur den geladenen Wert — ein
 * Literal faellt sonst genau dann nicht auf, wenn zufaellig kein Env gesetzt ist.
 *
 * Die Rotation der alten Werte selbst liegt beim Betreiber, Anleitung in
 * `docs/plans/2026-08-06T1114-mitgliedschafts-api/reverb-rotation.md`.
 */
it('haelt keine Reverb-Zugangsdaten als Literal in der Config', function () {
    $source = file_get_contents(base_path('config/reverb.php'));

    preg_match_all("/'(?:key|secret|app_id)'\s*=>\s*(.+)$/m", $source, $matches, PREG_SET_ORDER);

    expect($matches)->not->toBeEmpty();

    foreach ($matches as $match) {
        expect(trim($match[1]))->toStartWith('env(');
    }
});

it('erlaubt nicht mehr jede Origin', function () {
    $apps = config('reverb.apps.apps');

    expect($apps)->not->toBeEmpty();

    foreach ($apps as $app) {
        expect($app['allowed_origins'])
            ->not->toBeEmpty()
            ->not->toContain('*');
    }
});

it('nimmt den Host aus APP_URL immer in die erlaubten Origins auf', function () {
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);

    foreach (config('reverb.apps.apps') as $app) {
        expect($app['allowed_origins'])->toContain($host);
    }
});
