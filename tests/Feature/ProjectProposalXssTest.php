<?php

use App\Models\ProjectProposal;
use App\Support\RichTextMarkdownNormalizer;
use App\Support\RichTextSanitizer;

/*
 * The public detail page of a funding proposal renders its description with
 * `{!! !!}`, and that description is written by the applicant through a
 * rich-text editor. Before `RichTextSanitizer` existed, a stored `<script>`
 * executed for every visitor of that page — no login needed, measured at
 * status 200 — and the page is the one carrying the board's own approve and
 * payout actions, each callable as `$wire.<name>()` from injected script.
 *
 * ASSERTED ON THE RENDERED PAGE, not on the sanitizer. A unit test of the
 * sanitizer would have stayed green through the entire defect: the sanitizer
 * was not the thing that was missing, the CALL to one was, in two places at
 * once. What has to hold is that nothing active reaches a reader, whichever
 * way the row got into the database.
 */

/**
 * Payloads that reached the page unescaped before the fix, one per class of
 * vector, plus the substring that proves the payload survived.
 *
 * THE MARKER IS PAYLOAD-SPECIFIC, never a generic `<script` or `onerror`. The
 * assertion runs against the WHOLE rendered page, and that page legitimately
 * carries both: Livewire's and Vite's script tags, and an
 * `onerror="this.src=…"` image fallback on this very template
 * (`show.blade.php:475`). A generic needle fails on those and says nothing
 * about the proposal. Each needle below appears nowhere but in its own
 * payload, and each names the part that must not survive.
 *
 * @return array<string, array{0: string, 1: string}>
 */
dataset('xss payloads', [
    // Needs no structural tag at all — the branch that returns the input
    // unchanged because it "does not look like Markdown" was enough.
    'bare script' => ['hello <script>alert(11)</script>', 'alert(11)'],
    'script in a paragraph' => ['<p><script>alert(12)</script></p>', 'alert(12)'],
    // The structural branch: a heading or a table makes the normalizer hand
    // the whole document back untouched.
    'script behind a heading' => ['<h1>Projekt</h1><script>alert(13)</script>', 'alert(13)'],
    'img onerror inside a table' => ['<table><tr><td><img src=x onerror="alert(14)"></td></tr></table>', 'alert(14)'],
    'svg onload' => ['<h2>x</h2><svg onload="alert(15)"></svg>', 'alert(15)'],
    'event handler on an allowed element' => ['<p onclick="alert(16)">click</p>', 'alert(16)'],
    'javascript url' => ['<h3>x</h3><a href="javascript:alert(17)">go</a>', 'javascript:alert'],
    'data url in an image' => ['<h3>x</h3><img src="data:text/html;base64,PHNjcmlwdD4=">', 'data:text/html'],
    // Not a script, but the reason `style` is off the allowlist: it can put an
    // element over a control and turn a click into a click on something else.
    'positioning style' => ['<h1>x</h1><div style="position:fixed;inset:0">x</div>', 'position:fixed'],
]);

it('never serves an active payload on the public proposal page', function (string $payload, string $marker) {
    /*
     * Written straight to the column with `saveQuietly()`, deliberately
     * bypassing the form and the normalizer. That is not a shortcut — it is
     * the case the output layer exists for: rows that predate the sanitizing
     * save path, or that some future importer writes. If this test went
     * through the form, it would only ever measure the save-side half.
     */
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = $payload;
    $proposal->saveQuietly();

    $response = $this->get(route('association.projectSupport.item', $proposal));

    $response->assertOk();

    expect($response->getContent())->not->toContain($marker);
})->with('xss payloads');

it('strips the same payloads on the way into the database', function (string $payload, string $marker) {
    // The second half: `RichTextMarkdownNormalizer` is the single funnel every
    // write path goes through (create, edit, and the backfill command), so
    // nothing active is stored in the first place.
    expect((string) (new RichTextMarkdownNormalizer)->normalize($payload))->not->toContain($marker);
})->with('xss payloads');

it('keeps the formatting a real proposal uses', function () {
    /*
     * The other half of a sanitizer's job, and the half that is easy to get
     * wrong in the safe direction: an allowlist that eats legitimate content
     * is a bug too. Everything the editor can produce has to survive.
     */
    $html = '<h2>Projekt</h2>'
        .'<p>Ein <strong>wichtiges</strong> Projekt mit <em>Details</em> und <code>Code</code>.</p>'
        .'<ul><li>Punkt eins</li><li>Punkt zwei</li></ul>'
        .'<ol><li>Erstens</li></ol>'
        .'<blockquote>Zitat</blockquote>'
        .'<pre><code>$x = 1;</code></pre>'
        .'<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>B</td></tr></tbody></table>'
        .'<p><a href="https://einundzwanzig.space">Website</a></p>'
        .'<p><img src="https://example.com/i.png" alt="Bild"></p>';

    $sanitized = (new RichTextSanitizer)->sanitize($html);

    foreach (['<h2>', '<strong>', '<em>', '<code>', '<ul>', '<li>', '<ol>', '<blockquote>', '<pre>', '<table>', '<th>', '<td>'] as $tag) {
        // `toContain` is variadic — a message as a second argument would be a
        // second needle to look for.
        expect(str_contains((string) $sanitized, $tag))->toBeTrue("{$tag} must survive sanitizing.");
    }

    expect($sanitized)->toContain('https://einundzwanzig.space')
        ->toContain('https://example.com/i.png')
        ->toContain('alt="Bild"')
        ->toContain('Punkt eins');
});

it('sanitizes to a fixed point, so saving and rendering cannot compound', function () {
    /*
     * The value passes the sanitizer TWICE by design — once on save, once on
     * render — and an implementation that re-encoded its own output would turn
     * `&amp;` into `&amp;amp;` a little more on every edit. Pinned because the
     * two-layer design is what makes it possible.
     */
    $sanitizer = new RichTextSanitizer;

    $once = $sanitizer->sanitize('<p>A &amp; B — <code>user@example.com</code> <a href="https://e.com?a=1&amp;b=2">L</a></p>');

    expect($sanitizer->sanitize($once))->toBe($once)
        ->and($sanitizer->sanitize($sanitizer->sanitize($once)))->toBe($once);
});
