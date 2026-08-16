<?php

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;

beforeEach(function () {
    /*
     * Die ViewErrorBag steht in echten Anfragen dank `ShareErrorsFromSession`
     * in jeder View — Blade::render und Livewire::test laufen ohne diese
     * Middleware, und Flux' textarea liest `$errors` direkt. Ohne diese Zeile
     * scheitert nicht das Feld, sondern das Testgerüst.
     */
    View::share('errors', new ViewErrorBag);
});

/*
 * Das Markdown-Feld, das <flux:editor> ersetzt hat — geprüft an dem, was die
 * Verdrahtung tatsächlich tragen muss.
 *
 * DIE TOOLBAR FINDET IHR TEXTAREA ÜBER EINE id (`<markdown-toolbar for="…">`).
 * Stimmen die beiden nicht überein, passiert nichts Sichtbares: kein Fehler,
 * keine Meldung, die Knöpfe tun einfach nichts. Genau deshalb steht die
 * Verbindung hier als Zusicherung und nicht als Annahme.
 */

it('verbindet die Werkzeugleiste mit ihrem Eingabefeld', function () {
    $html = Blade::render(
        '<x-markdown-field model="form.description" label="Beschreibung" />'
    );

    preg_match('/<markdown-toolbar[^>]*\sfor="([^"]+)"/', $html, $toolbar);
    preg_match('/<textarea[^>]*\sid="([^"]+)"/', $html, $textarea);

    expect($toolbar[1] ?? 'A')->toBe($textarea[1] ?? 'B',
        'Die id des Textarea und das for der Werkzeugleiste müssen übereinstimmen, sonst tun die Knöpfe nichts.');
});

it('bindet das Eingabefeld an das angegebene Modell', function () {
    $html = Blade::render('<x-markdown-field model="form.description" />');

    expect($html)->toContain('wire:model.live.debounce.500ms="form.description"')
        // `.live`, weil die Vorschau mitlaufen soll — mit `.blur` bliebe sie
        // stehen, bis das Feld den Fokus verliert.
        ->and($html)->toContain('<markdown-toolbar');
});

it('vergibt für dasselbe Modell dieselbe id über mehrere Durchläufe', function () {
    /*
     * Livewire rendert die Komponente bei jeder Aktualisierung neu. Eine
     * zufällige id (etwa aus `uniqid()`) wechselte dabei jedes Mal und risse
     * die Verbindung zur Werkzeugleiste — sichtbar erst, wenn ein Nutzer nach
     * dem ersten Tastendruck auf „Fett" drückt.
     */
    $first = Blade::render('<x-markdown-field model="form.description" />');
    $second = Blade::render('<x-markdown-field model="form.description" />');

    preg_match('/<textarea[^>]*\sid="([^"]+)"/', $first, $a);
    preg_match('/<textarea[^>]*\sid="([^"]+)"/', $second, $b);

    expect($a[1])->toBe($b[1]);
});

it('zeigt die Vorschau live, gerendert wie die spätere Seite', function () {
    /*
     * Der eigentliche Gewinn gegenüber einem Editor mit eigener JS-Vorschau:
     * Hier rendert der Server, mit demselben CommonMark-Aufbau und demselben
     * Sanitizer wie die Detailseite. Was der Autor sieht, IST das Ergebnis —
     * inklusive Tabellen und inklusive dessen, was der Sanitizer entfernt.
     */
    $pleb = EinundzwanzigPleb::query()->create([
        'pubkey' => 'pk_'.Str::random(20),
        'npub' => 'npub_'.Str::random(20),
    ]);
    $proposal = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $pleb->id]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $proposal])
        ->set('form.description', "# Titel\n\n| A | B |\n| --- | --- |\n| 1 | 2 |")
        ->assertSee('<h1>Titel</h1>', escape: false)
        ->assertSee('<table>', escape: false)
        ->assertSee('<td>1</td>', escape: false);
});

it('lässt aktives HTML auch in der Vorschau nicht durch', function () {
    // Die Vorschau ist eine Ausgabe wie jede andere und wird mit `{!! !!}`
    // gerendert — sie muss durch denselben Filter.
    $pleb = EinundzwanzigPleb::query()->create([
        'pubkey' => 'pk_'.Str::random(20),
        'npub' => 'npub_'.Str::random(20),
    ]);
    $proposal = ProjectProposal::factory()->create(['einundzwanzig_pleb_id' => $pleb->id]);

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.project-support.form.edit', ['projectProposal' => $proposal])
        ->set('form.description', '<script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', escape: false);
});
