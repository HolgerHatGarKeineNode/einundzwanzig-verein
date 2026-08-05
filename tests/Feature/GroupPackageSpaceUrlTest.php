<?php

use App\Providers\GroupPackageServiceProvider;

/**
 * Die Space-Adresse muss mit einem Schrägstrich enden.
 *
 * welshman normalisiert jede Relay-Adresse darauf, und alle Vorgaben des
 * Packages tragen ihn. Der Relay-Riegel (`window.__nostrRelays`, siehe
 * resources/js/projectChatFeed.js) wird aber roh durchgereicht — ohne
 * Normalisierung stünden für denselben Relay zwei verschiedene Zeichenketten
 * im Umlauf, und der Riegel griffe ins Leere.
 *
 * Der Fehler ist nur in Produktion sichtbar: Die lokale Testadresse trug den
 * Schrägstrich schon, `NOSTR_SPACE_URL=wss://…space` (ohne) ist aber die
 * naheliegende Schreibweise.
 */
function registerGroupPackage(?string $spaceUrl): string
{
    config(['group.space_url' => $spaceUrl]);

    (new GroupPackageServiceProvider(app()))->register();

    return (string) config('group.space_url');
}

it('ergänzt den fehlenden Schrägstrich', function () {
    expect(registerGroupPackage('wss://group.einundzwanzig.space'))
        ->toBe('wss://group.einundzwanzig.space/');
});

it('lässt eine bereits normalisierte Adresse unverändert', function () {
    expect(registerGroupPackage('wss://group.einundzwanzig.space/'))
        ->toBe('wss://group.einundzwanzig.space/');
});

it('erzeugt aus einer leeren Adresse keinen nackten Schrägstrich', function (?string $leer) {
    expect(registerGroupPackage($leer))->toBeEmpty();
})->with([
    'nicht gesetzt' => null,
    'leer' => '',
    'nur Leerzeichen' => '   ',
]);

it('hängt keinen zweiten Schrägstrich an', function () {
    expect(registerGroupPackage('ws://localhost:3341//'))
        ->toBe('ws://localhost:3341/');
});
