<?php

use App\Models\ProjectProposal;

/*
 * Die einmalige Umstellung der Spalte von HTML auf Markdown. Sie läuft
 * unbeaufsichtigt über Produktionsdaten und ist nicht umkehrbar, also ist die
 * Grenze das Gepinnte: Auszeichnung darf sich ändern, ein Wort nicht
 * verschwinden.
 */

it('wandelt gespeichertes HTML in Markdown', function () {
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = '<h2>Ziel</h2><p>Ein <strong>wichtiges</strong> Projekt.</p><ul><li>Punkt eins</li></ul>';
    $proposal->saveQuietly();

    $this->artisan('project-proposals:to-markdown')->assertSuccessful();

    $after = (string) $proposal->fresh()->description;

    expect($after)->toContain('## Ziel')
        ->toContain('**wichtiges**')
        ->toContain('- Punkt eins')
        ->not->toContain('<h2>');
});

it('lässt eine Beschreibung in Ruhe, die schon Markdown ist', function () {
    // Idempotenz: Ein zweiter Lauf über dieselbe Tabelle darf nichts mehr
    // anfassen, sonst wandert der Inhalt bei jeder Ausführung weiter.
    $markdown = "## Ziel\n\nEin **wichtiges** Projekt.\n\n- Punkt eins";

    $proposal = ProjectProposal::factory()->create();
    $proposal->description = $markdown;
    $proposal->saveQuietly();

    $this->artisan('project-proposals:to-markdown')->assertSuccessful();

    expect((string) $proposal->fresh()->description)->toBe($markdown);
});

it('entschärft ein aktives Element, ohne seinen Text wegzuwerfen', function () {
    /*
     * GEMESSEN, NICHT ANGENOMMEN: `strip_tags => true` wirft das `<script>`
     * weg und behält seinen Inhalt als gewöhnlichen Markdown-Text. Beides ist
     * hier richtig — der Text bleibt dem Antragsteller erhalten, und weil er
     * nun Text ist, kann er nichts mehr auslösen.
     *
     * Erwartet war ursprünglich das Gegenteil (Zeile wird übersprungen); die
     * Erwartung stammte aus der Vermutung, ein Skript-Inhalt gelte als
     * verloren. Der Testlauf hat das widerlegt, und das Verhalten ist das
     * bessere: Die Wortprüfung schlägt nur an, wenn wirklich etwas fehlt.
     */
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = '<p>Sichtbar</p><script>ein Text, den nur das Script traegt</script>';
    $proposal->saveQuietly();

    $this->artisan('project-proposals:to-markdown')->assertSuccessful();

    $proposal = $proposal->fresh();

    expect($proposal->description)->toContain('Sichtbar')
        ->toContain('ein Text, den nur das Script traegt')
        ->not->toContain('<script')
        // Und in der Ausgabe ist daraus Text geworden, kein Element.
        ->and($proposal->safeDescription())->not->toContain('<script');
});

it('schreibt im Probelauf nichts', function () {
    $original = '<p>Unverändert</p>';

    $proposal = ProjectProposal::factory()->create();
    $proposal->description = $original;
    $proposal->saveQuietly();

    $this->artisan('project-proposals:to-markdown', ['--dry-run' => true])->assertSuccessful();

    expect((string) $proposal->fresh()->description)->toBe($original);
});

it('erhält die gerenderte Bedeutung über die Wandlung hinweg', function () {
    /*
     * Die eigentliche Zusicherung, und sie prüft das Ergebnis, nicht den Weg:
     * Was ein Leser vor der Migration sah, sieht er danach auch. Verglichen
     * wird der Textgehalt der gerenderten Ausgabe — die Auszeichnung darf
     * abweichen, denn genau die wird ja umgeformt.
     */
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = '<h2>Ziel</h2><p>Ein <strong>wichtiges</strong> Projekt mit '
        .'<a href="https://einundzwanzig.space">Link</a>.</p><ul><li>Punkt eins</li><li>Punkt zwei</li></ul>';
    $proposal->saveQuietly();

    $textOf = fn (string $html): string => trim(preg_replace('/\s+/u', ' ',
        html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</h2>'], ' ', $html)), ENT_QUOTES | ENT_HTML5)) ?? '');

    /*
     * Verglichen wird gegen den Text des URSPRÜNGLICHEN HTML, nicht gegen
     * `safeDescription()` von vorher — und der Grund dafür ist genau der
     * Anlass dieser Migration: Solange HTML in einer Spalte steht, die als
     * Markdown gerendert wird, kommt es escaped heraus und der Leser sieht
     * `&lt;h2&gt;Ziel&lt;/h2&gt;`. Der Zustand VOR der Wandlung ist der
     * kaputte; ihn als Referenz zu nehmen hieße, den Defekt festzuschreiben.
     */
    $before = $textOf((string) $proposal->description);

    $this->artisan('project-proposals:to-markdown')->assertSuccessful();

    expect($textOf($proposal->fresh()->safeDescription()))->toBe($before);
});

it('verliert keinen Zeilenumbruch hinter einem Link', function () {
    /*
     * AN EINEM ECHTEN ANTRAG GEMESSEN, den die Wortprüfung auf Produktion
     * gestoppt hat: `league/html-to-markdown` verschluckt einen Umbruch, der
     * direkt hinter einem Inline-Element steht, und klebt die Wörter davor und
     * danach zusammen. Ohne die Vorverarbeitung stünde
     * „…einundzwanzigwritedamit wir…" auf der Seite.
     *
     * Der Test steht hier und nicht als Notiz, weil der Fehler leise ist: Er
     * erzeugt keinen Abbruch, nur ein falsches Wort mitten im Text eines
     * Mitglieds.
     */
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = "<p>Nutzt den Hashtag: <a href=\"https://primal.net/x\">#einundzwanzigwrite</a>\ndamit wir euch finden</p>";
    $proposal->saveQuietly();

    $this->artisan('project-proposals:to-markdown')->assertSuccessful();

    $rendered = $proposal->fresh()->safeDescription();

    expect($rendered)->toContain('#einundzwanzigwrite')
        ->toContain('damit wir euch finden')
        ->not->toContain('einundzwanzigwritedamit');
});
