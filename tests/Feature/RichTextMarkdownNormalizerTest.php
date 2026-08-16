<?php

use App\Support\RichTextMarkdownNormalizer;

beforeEach(function () {
    $this->normalizer = new RichTextMarkdownNormalizer;
});

it('returns null and empty values untouched', function () {
    expect($this->normalizer->normalize(null))->toBeNull()
        ->and($this->normalizer->normalize(''))->toBe('')
        ->and($this->normalizer->normalize('   '))->toBe('   ');
});

it('converts heading markdown wrapped in paragraph tags', function () {
    $html = '<p># EINUNDZWANZIG STANDUP</p><p>## Wer ich bin</p><p>Regular text.</p>';

    $result = $this->normalizer->normalize($html);

    expect($result)->toContain('<h1')
        ->toContain('EINUNDZWANZIG STANDUP')
        ->toContain('<h2')
        ->toContain('Wer ich bin')
        ->toContain('Regular text.');
});

it('converts bullet list markdown wrapped in paragraph tags', function () {
    $html = '<p>- first item</p><p>- second item</p><p>- third item</p>';

    $result = $this->normalizer->normalize($html);

    expect($result)->toContain('<ul>')
        ->toContain('first item')
        ->toContain('second item')
        ->toContain('third item')
        ->and(substr_count($result, '<li>'))->toBe(3);
});

it('leaves structural html untouched when headings already exist', function () {
    $html = '<h1>Real heading</h1><p># not a heading</p>';

    expect($this->normalizer->normalize($html))->toBe($html);
});

it('leaves structural html untouched when list tags already exist', function () {
    $html = '<ul><li>existing</li></ul><p>- not a list</p>';

    expect($this->normalizer->normalize($html))->toBe($html);
});

it('leaves plain paragraph html untouched when it is not markdown', function () {
    $html = '<p>Just some normal text without any markdown syntax.</p>';

    expect($this->normalizer->normalize($html))->toBe($html);
});

it('renders pure plain text with paragraph breaks as html paragraphs', function () {
    $text = "First paragraph with some text.\n\nSecond paragraph follows.";

    $result = $this->normalizer->normalize($text);

    expect($result)->toContain('<p>First paragraph with some text.</p>')
        ->toContain('<p>Second paragraph follows.</p>');
});

it('renders plain text markdown (headings, lists, images) as html', function () {
    $text = "## Heading Two\n\nSome intro line.\n\n- first\n- second\n\n![alt](https://example.com/img.png)";

    $result = $this->normalizer->normalize($text);

    expect($result)->toContain('<h2')
        ->toContain('Heading Two')
        ->toContain('<ul>')
        ->toContain('<li>first</li>')
        ->toContain('<img')
        ->toContain('https://example.com/img.png');
});

it('is idempotent when re-run on already-rendered output', function () {
    $text = "## Heading\n\nBody text.";

    $first = $this->normalizer->normalize($text);
    $second = $this->normalizer->normalize($first);

    expect($second)->toBe($first);
});

it('preserves inline bold, code and links when converting pasted markdown', function () {
    $html = '<p><strong>Antragsteller:</strong> DrShift — <code>user@example.com</code></p>'
        .'<p><a href="https://example.com">Website</a></p>'
        .'<p># Heading</p>';

    /*
     * Compared after decoding entities, not byte for byte. The sanitizer the
     * normalizer now ends with writes characters that are dangerous at a
     * context boundary as numeric entities — `@` becomes `&#64;`, `=` inside a
     * URL becomes `&#61;` — which a browser renders back to the original and a
     * `toContain('<code>user@example.com</code>')` does not. Asserting on the
     * decoded form keeps this test about what it is for (inline formatting
     * survives the conversion) instead of pinning an escaping detail of a
     * dependency. Verified idempotent: sanitizing twice yields the same
     * string, so nothing accumulates across save and render.
     */
    $result = html_entity_decode($this->normalizer->normalize($html), ENT_QUOTES | ENT_HTML5);

    expect($result)->toContain('<h1')
        ->toContain('Heading')
        ->toContain('<strong>Antragsteller:</strong>')
        ->toContain('<code>user@example.com</code>')
        ->toContain('<a href="https://example.com">Website</a>');
});

it('preserves images embedded via img tags', function () {
    $html = '<p># Heading</p><p><img src="https://example.com/i.png" alt="caption"></p>';

    $result = $this->normalizer->normalize($html);

    expect($result)->toContain('<h1')
        ->toContain('<img')
        ->toContain('src="https://example.com/i.png"')
        ->toContain('alt="caption"');
});

it('rendert GitHub-Markdown: Tabellen, Durchstreichen und nackte URLs', function () {
    /*
     * DIE ERWEITERUNGEN, DIE JAHRELANG FEHLTEN. CommonMark ist die
     * Kernspezifikation und kennt keine Tabellen — mit
     * `DisallowedRawHtmlExtension` als einzigem Eintrag in
     * config/markdown.php landete eine Markdown-Tabelle als Rohtext in einem
     * <p>, und es sah aus, als sei der Renderer defekt. Er war es nie; es war
     * die Voreinstellung.
     *
     * Gepinnt, weil der Ausfall LAUTLOS war: Kein Fehler, kein Log, nur eine
     * Beschreibung, die auf der Seite aussieht wie roher Text. Nimmt jemand
     * die Erweiterungen wieder heraus, fällt das hier auf und nicht erst
     * einem Mitglied.
     */
    $markdown = "| Posten | Sats |\n| --- | --- |\n| Sticker | 21000 |\n\n"
        .'~~gestrichen~~ und https://einundzwanzig.space';

    $result = $this->normalizer->normalize($markdown);

    expect($result)->toContain('<table>')
        ->toContain('<th>Posten</th>')
        ->toContain('<td>21000</td>')
        ->toContain('<del>gestrichen</del>')
        ->toContain('<a href="https://einundzwanzig.space">');
});

it('erzeugt keine Formularelemente aus Markdown', function () {
    /*
     * Die bewusst NICHT geladene Erweiterung. `GithubFlavoredMarkdownExtension`
     * hätte TaskList mitgebracht, und die macht aus `- [ ]` ein
     * `<input type="checkbox">`. Der Sanitizer müsste `<input>` erlauben und
     * könnte den `type` nicht einschränken — ein `type="password"` in einem
     * Förderantrag wäre ein Phishing-Formular auf der Vereinsseite.
     *
     * Wer TaskList später will, muss zuerst diesen Test beantworten.
     */
    $result = (string) $this->normalizer->normalize("- [ ] offen\n- [x] erledigt");

    expect($result)->not->toContain('<input')
        ->not->toContain('type="checkbox"');
});
