<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

/**
 * Bindet das Package einundzwanzig/group als reine Bausteinsammlung ein.
 *
 * Das Package ist als eigenständige Chat-Anwendung gebaut: sein eigener
 * GroupServiceProvider registriert Routen (/spaces, /rooms/{h}, /settings, …),
 * einen zweiten Login-Pfad über NIP-98 und einen Scheduler. Nichts davon wollen
 * wir hier — der Verein hat eine eigene Navigation und einen eigenen
 * Nostr-Login (kind 22242, siehe App\Support\NostrAuth). Der Package-Provider
 * ist deshalb in composer.json unter extra.laravel.dont-discover abgeschaltet.
 *
 * Übrig bleibt, was wir tatsächlich brauchen: die Views und Blade-Komponenten
 * der Chat-Insel. Wächst der Bedarf, kommt hier eine Zeile dazu — bewusst und
 * einzeln, statt den ganzen Provider zu erben.
 */
class GroupPackageServiceProvider extends ServiceProvider
{
    /**
     * Pfad zum installierten Package.
     */
    protected function packagePath(string $sub = ''): string
    {
        return base_path('vendor/einundzwanzig/group'.$sub);
    }

    public function register(): void
    {
        $this->mergeConfigFrom($this->packagePath('/config/group.php'), 'group');

        config(['group.space_url' => $this->normalizedSpaceUrl()]);
    }

    /**
     * Space-Adresse mit abschließendem Schrägstrich.
     *
     * welshman normalisiert jede Relay-Adresse auf einen abschließenden
     * Schrägstrich, und sämtliche Vorgaben des Packages tragen ihn. Unser
     * Relay-Riegel (`window.__nostrRelays`, siehe projectChatFeed.js) wird
     * hingegen ununtersucht übernommen — `core.ts` reicht ihn roh durch.
     *
     * Ohne diese Normalisierung stünden für denselben Relay zwei verschiedene
     * Zeichenketten im Umlauf: `__nostrSpace` normalisiert, der Riegel nicht.
     * Ein `NOSTR_SPACE_URL` ohne Schrägstrich — die naheliegende Schreibweise —
     * ließe damit den Riegel ins Leere greifen.
     *
     * Lokal fiel das nie auf, weil die Testadresse den Schrägstrich schon trug.
     */
    protected function normalizedSpaceUrl(): string
    {
        $url = trim((string) config('group.space_url', ''));

        return $url === '' ? '' : rtrim($url, '/').'/';
    }

    public function boot(): void
    {
        $views = $this->packagePath('/resources/views');

        // group::partials.chat-row, group::partials.chat-composer, …
        $this->loadViewsFrom($views, 'group');

        // Die Übersetzungen des Packages werden BEWUSST NICHT geladen.
        //
        // Seine Views nutzen den deutschen Text selbst als Key — `lang/en.json`
        // übersetzt ihn also ins Englische. Der Verein läuft mit APP_LOCALE=en
        // (seine eigenen Texte stehen fest im Markup, deutsch). Mit geladenen
        // Package-Übersetzungen erschien der eingebettete Chat deshalb auf
        // Englisch mitten in einer deutschen Seite („Write a message…" unter
        // „Chat zum Antrag"). Ohne sie liefert __() den Key zurück — Deutsch,
        // unabhängig von der eingestellten Locale.

        // <x-group::nostr-avatar>, <x-group::profile-card>, …
        Blade::anonymousComponentPath($views.'/components', 'group');

        // Package-owned Flux icons (<flux:icon.pin>) used by the chat components. Without
        // this path `view:cache` fails with "Unable to locate a class or view for component
        // [flux::icon.pin]" — the deploy of 2026-10-22 broke on exactly that line.
        Blade::anonymousComponentPath($views.'/flux', 'flux');
    }
}
