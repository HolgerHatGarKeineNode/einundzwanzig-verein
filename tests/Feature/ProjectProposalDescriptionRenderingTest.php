<?php

use App\Support\MarkdownRenderer;

/*
 * How a project description reads on the page: paragraphs, line breaks,
 * images. The security side lives in ProjectProposalXssTest and
 * MarkdownXssProtectionTest; this file covers layout only.
 */

it('starts a new paragraph at a blank line', function () {
    $html = (new MarkdownRenderer)->toSafeHtml("First paragraph.\n\nSecond paragraph.");

    expect($html)->toBe("<p>First paragraph.</p>\n<p>Second paragraph.</p>");
});

it('keeps a single line break as a line break', function () {
    $html = (new MarkdownRenderer)->toSafeHtml("Line one\nLine two");

    expect($html)->toBe("<p>Line one<br />\nLine two</p>");
});

it('renders a markdown image with an https source', function () {
    $html = (new MarkdownRenderer)->toSafeHtml('![Logo](https://example.com/logo.png)');

    expect($html)->toContain('<img src="https://example.com/logo.png" alt="Logo" />');
});

it('renders headings and code blocks without stripped leftovers', function () {
    $html = (new MarkdownRenderer)->toSafeHtml("## Plan\n\n```\necho 1;\n```");

    // Heading permalinks and Shiki spans used to be generated and then
    // removed again by the sanitizer.
    expect($html)->toBe("<h2>Plan</h2>\n<pre><code>echo 1;\n</code></pre>");
});

it('loads the typography plugin that gives .prose its spacing', function () {
    /*
     * Without the plugin `.prose` is a no-op and preflight resets every
     * paragraph, list and heading margin to zero — descriptions rendered as
     * one unbroken wall of text in production.
     */
    $css = file_get_contents(resource_path('css/app.css'));
    $package = json_decode(file_get_contents(base_path('package.json')), true);

    expect($css)->toContain('@plugin "@tailwindcss/typography";')
        ->and($package['devDependencies'] ?? [])->toHaveKey('@tailwindcss/typography');
});
