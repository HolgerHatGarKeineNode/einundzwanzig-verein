<?php

use App\Models\ProjectProposal;
use App\Support\MarkdownRenderer;
use App\Support\RichTextSanitizer;

/*
 * Die öffentliche Detailseite eines Förderantrags gibt seine Beschreibung mit
 * `{!! !!}` aus, und geschrieben hat sie der Antragsteller. Vor
 * `ProjectProposal::safeDescription()` lief ein gespeichertes `<script>` bei
 * jedem Besucher — ohne Anmeldung, gemessen bei Status 200 — und die Seite ist
 * dieselbe, auf der der Vorstand zustimmt und Auszahlungen bucht.
 *
 * SEIT DIE SPALTE MARKDOWN TRÄGT, schützen ZWEI Mechanismen nacheinander, und
 * die Tests unterscheiden sie:
 *
 *   1. `html_input => 'escape'` — rohes HTML im Markdown wird zu sichtbarem
 *      TEXT. Ein `<script>` in der Datenbank erscheint als `&lt;script&gt;`
 *      auf der Seite: lesbar, unwirksam.
 *   2. `RichTextSanitizer` — die Allowlist über dem gerenderten Ergebnis, für
 *      den Fall, dass die erste Schicht je verstellt wird.
 *
 * WAS DIESE TESTS DESHALB PRÜFEN, ist nicht mehr „der Payload-Text kommt nicht
 * vor" — er DARF vorkommen, escaped, und das ist korrektes Verhalten. Geprüft
 * wird, dass er nicht AKTIV ist: kein Element, kein Attribut, kein Schema.
 */

/**
 * Was an einem HTML-Fragment aktiv werden könnte — als STRUKTUR gelesen, nicht
 * als Zeichenkette.
 *
 * DER GRUND, warum das ein DOM-Durchlauf sein muss und keine Textsuche: Seit
 * die Spalte Markdown trägt, kommt rohes HTML escaped heraus. `position:fixed`
 * oder `onerror` stehen dann als sichtbarer TEXT in der Ausgabe — korrekt und
 * harmlos —, und ein `str_contains()` schlägt trotzdem an. Ein Test, der so
 * fehlschlägt, sagt nichts über Sicherheit; er sagt nur, dass jemand über HTML
 * geschrieben hat.
 *
 * Der Parser sieht den Unterschied: `&lt;script&gt;` ist ein Textknoten,
 * `<script>` ein Element.
 *
 * @return array{elements: list<string>, attributes: list<string>, urls: list<string>}
 */
function activeMarkupIn(string $html): array
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $elements = [];
    $attributes = [];
    $urls = [];

    foreach (new DOMXPath($dom)->query('//*') as $node) {
        $elements[] = strtolower($node->nodeName);

        foreach ($node->attributes ?? [] as $attribute) {
            $attributes[] = strtolower($attribute->nodeName);

            if (in_array(strtolower($attribute->nodeName), ['href', 'src'], true)) {
                $urls[] = strtolower(trim($attribute->nodeValue ?? ''));
            }
        }
    }

    return ['elements' => $elements, 'attributes' => $attributes, 'urls' => $urls];
}

/**
 * Payloads, die vor dem Fix ausgeführt wurden, je einer pro Vektorklasse.
 *
 * @return array<string, array{0: string}>
 */
dataset('xss payloads', [
    'bare script' => ['hallo <script>alert(11)</script>'],
    'script in a paragraph' => ['<p><script>alert(12)</script></p>'],
    'script behind a heading' => ['<h1>Projekt</h1><script>alert(13)</script>'],
    'img onerror inside a table' => ['<table><tr><td><img src=x onerror="alert(14)"></td></tr></table>'],
    'svg onload' => ['<h2>x</h2><svg onload="alert(15)"></svg>'],
    'event handler on an allowed element' => ['<p onclick="alert(16)">click</p>'],
    'javascript url' => ['<h3>x</h3><a href="javascript:alert(17)">go</a>'],
    'data url in an image' => ['<h3>x</h3><img src="data:text/html;base64,PHNjcmlwdD4=">'],
    'positioning style' => ['<h1>x</h1><div style="position:fixed;inset:0">x</div>'],
    // Markdown-eigene Vektoren, die es vorher nicht gab: Der Renderer selbst
    // darf aus Markdown-Syntax kein aktives Ziel bauen.
    'markdown link with javascript scheme' => ['[klick](javascript:alert(18))'],
    'markdown image with data url' => ['![x](data:text/html;base64,PHN2Zz4=)'],
]);

it('gibt keinen aktiven Payload aus, egal wie die Zeile entstand', function (string $payload) {
    /*
     * Mit `saveQuietly()` direkt in die Spalte geschrieben, am Formular
     * vorbei. Das ist kein Abkürzen, sondern der Fall, für den die
     * Ausgabeschicht existiert: Zeilen aus einem Import, aus der Konsole oder
     * aus der Zeit vor dieser Änderung.
     */
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = $payload;
    $proposal->saveQuietly();

    $markup = activeMarkupIn($proposal->safeDescription());

    expect(array_intersect($markup['elements'], ['script', 'iframe', 'svg', 'object', 'embed', 'input', 'form', 'style']))
        ->toBe([], 'Ein Element, das Code oder Eingaben tragen kann, hat die Allowlist überlebt.');

    $handlers = array_values(array_filter($markup['attributes'], fn (string $a): bool => str_starts_with($a, 'on')));

    expect($handlers)->toBe([], 'Ein Event-Handler-Attribut hat die Allowlist überlebt.')
        ->and(array_intersect($markup['attributes'], ['style']))->toBe([], '`style` erlaubt es, ein Element über einen Knopf zu legen.');

    foreach ($markup['urls'] as $url) {
        expect(str_starts_with($url, 'http://') || str_starts_with($url, 'https://') || $url === '')
            ->toBeTrue("Eine URL mit unerlaubtem Schema hat überlebt: {$url}");
    }

    // Und die Seite liefert trotzdem aus, statt zu brechen.
    $this->get(route('association.projectSupport.item', $proposal))->assertOk();
})->with('xss payloads');

it('zeigt rohes HTML als lesbaren Text an, statt es zu verschlucken', function () {
    /*
     * Die Kehrseite von `html_input => 'escape'`, und sie ist ein Gewinn:
     * Wer in einer Beschreibung über HTML schreibt, sieht sein `<script>` auf
     * der Seite stehen — als Text. Vorher verschwand es spurlos (oder wurde
     * ausgeführt). Beides ist schlechter als es zu zeigen.
     */
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = 'Beispiel: <script>alert(1)</script> ist gefährlich.';
    $proposal->saveQuietly();

    $html = $proposal->safeDescription();

    expect($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain('alert(1)')
        ->and($html)->not->toContain('<script');
});

it('rendert die Markdown-Auszeichnung einer echten Beschreibung', function () {
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = "## Ziel\n\nEin **wichtiges** Projekt.\n\n"
        ."- Punkt eins\n- Punkt zwei\n\n"
        ."| Posten | Sats |\n| --- | --- |\n| Sticker | 21000 |\n\n"
        .'Mehr auf [der Website](https://einundzwanzig.space).';

    $proposal->saveQuietly();

    $html = $proposal->safeDescription();

    expect($html)->toContain('<h2>Ziel</h2>')
        ->toContain('<strong>wichtiges</strong>')
        ->toContain('<li>Punkt eins</li>')
        ->toContain('<table>')
        ->toContain('<td>21000</td>')
        ->toContain('<a href="https://einundzwanzig.space">');
});

it('sanitisiert zu einem Festpunkt, damit nichts sich aufschaukelt', function () {
    $sanitizer = new RichTextSanitizer;

    $once = $sanitizer->sanitize('<p>A &amp; B — <code>user@example.com</code> <a href="https://e.com?a=1&amp;b=2">L</a></p>');

    expect($sanitizer->sanitize($once))->toBe($once);
});

it('gibt für leere Beschreibungen einen leeren String zurück, nicht null', function () {
    // Der Rückgabewert landet in einem `{!! !!}`; ein `null` dort wäre ein
    // stiller TypeError in einer Blade-Datei statt einer leeren Seite.
    expect((new MarkdownRenderer)->toSafeHtml(null))->toBe('')
        ->and((new MarkdownRenderer)->toSafeHtml('   '))->toBe('');
});
