<?php

use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\DisallowedRawHtml\DisallowedRawHtmlExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use Spatie\LaravelMarkdown\MarkdownRenderer;

return [
    'code_highlighting' => [
        /*
         * To highlight code, we'll use Shiki under the hood. Make sure it's installed.
         *
         * More info: https://spatie.be/docs/laravel-markdown/v1/installation-setup
         */
        'enabled' => true,

        /*
         * The name of or path to a Shiki theme
         *
         * More info: https://github.com/shikijs/shiki/blob/main/docs/themes.md
         */
        'theme' => 'github-light',
    ],

    /*
     * When enabled, anchor links will be added to all titles
     */
    'add_anchors_to_headings' => true,

    /**
     * When enabled, anchors will be rendered as links.
     */
    'render_anchors_as_links' => false,

    /*
     * These options will be passed to the league/commonmark package which is
     * used under the hood to render markdown.
     *
     * More info: https://spatie.be/docs/laravel-markdown/v1/using-the-blade-component/passing-options-to-commonmark
     */
    'commonmark_options' => [
        /*
         * `escape`, nicht `allow`. Mit `allow` liess CommonMark rohes HTML aus
         * dem Markdown-Quelltext unveraendert durch — nachgemessen gingen
         * `<img src=x onerror=…>`, `<svg onload=…>`, `<div onclick=…>` und ein
         * rohes `<a href="javascript:…">` hindurch. Die
         * DisallowedRawHtmlExtension unten faengt davon nur `<script>` und
         * `<iframe>`; sie ist eine Denyliste und war nie als einzige Schranke
         * gedacht. `allow_unsafe_links => false` greift ebenfalls nicht, weil
         * es Markdown-LINKS prueft und kein rohes HTML-Attribut.
         *
         * Kostet keine echte Funktion: der Rich-Text dieser Anwendung kommt
         * aus dem Editor (flux:editor) und laeuft ueber
         * RichTextMarkdownNormalizer, nicht als rohes HTML in einem
         * Markdown-Dokument.
         */
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
    ],

    /*
     * Rendering markdown to HTML can be resource intensive. By default
     * we'll cache the results.
     *
     * You can specify the name of a cache store here. When set to `null`
     * the default cache store will be used. If you do not want to use
     * caching set this value to `false`.
     */
    'cache_store' => null,

    /*
     * When cache_store is enabled, this value will be used to determine
     * how long the cache will be valid. If you set this to `null` the
     * cache will never expire.
     *
     */
    'cache_duration' => null,

    /*
     * This class will convert markdown to HTML
     *
     * You can change this to a class of your own to greatly
     * customize the rendering process
     *
     * More info: https://spatie.be/docs/laravel-markdown/v1/advanced-usage/customizing-the-rendering-process
     */
    'renderer_class' => MarkdownRenderer::class,

    /*
     * These extensions should be added to the markdown environment. A valid
     * extension implements League\CommonMark\Extension\ExtensionInterface
     *
     * More info: https://commonmark.thephpleague.com/2.4/extensions/overview/
     */
    /*
     * WAS COMMONMARK OHNE DIESE LISTE NICHT KANN, und was jahrelang niemand
     * bemerkt hat: CommonMark ist die Kernspezifikation, und Tabellen stehen
     * NICHT darin. Mit `DisallowedRawHtmlExtension` als einzigem Eintrag
     * landete eine Markdown-Tabelle als Rohtext in einem <p> — nachgemessen:
     *
     *   | Posten | Sats |   ->  <p>| Posten | Sats |
     *   | ---    | ---  |        | ---    | ---  | …</p>
     *   ~~alt~~              ->  <p>~~alt~~</p>
     *   https://…            ->  kein Link
     *
     * Das sah aus, als sei der Markdown-Renderer kaputt, war aber die
     * Voreinstellung: Was ein Nutzer als „Markdown" schreibt, ist meist
     * GitHub-Flavored Markdown, und dessen Zusätze sind einzeln zuzuschalten.
     *
     * EINZELN STATT `GithubFlavoredMarkdownExtension`, und der Grund ist der
     * Sanitizer: Das Bündel bringt zusätzlich TaskList mit, und die erzeugt
     * `<input type="checkbox">`. Ein Formularelement in einer von Mitgliedern
     * geschriebenen Beschreibung ist nichts, was hier gebraucht wird — und
     * `App\Support\RichTextSanitizer` müsste `<input>` erlauben, ohne den
     * `type` einschränken zu können. Ein `type="password"` in einem
     * Förderantrag ist ein Phishing-Formular. Die drei unten erzeugen
     * ausschließlich Elemente, die ohnehin auf der Allowlist stehen.
     */
    'extensions' => [
        DisallowedRawHtmlExtension::class,
        TableExtension::class,
        StrikethroughExtension::class,
        AutolinkExtension::class,
    ],

    /*
     * These block renderers should be added to the markdown environment. A valid
     * renderer implements League\CommonMark\Renderer\NodeRendererInterface;
     *
     * More info: https://commonmark.thephpleague.com/2.4/customization/rendering/
     */
    'block_renderers' => [
        // ['class' => FencedCode::class, 'renderer' => MyCustomCodeRenderer::class, 'priority' => 0]
    ],

    /*
     * These inline renderers should be added to the markdown environment. A valid
     * renderer implements League\CommonMark\Renderer\NodeRendererInterface;
     *
     * More info: https://commonmark.thephpleague.com/2.4/customization/rendering/
     */
    'inline_renderers' => [
        // ['class' => FencedCode::class, 'renderer' => MyCustomCodeRenderer::class, 'priority' => 0]
    ],

    /*
     * These inline parsers should be added to the markdown environment. A valid
     * parser implements League\CommonMark\Renderer\InlineParserInterface;
     *
     * More info: https://commonmark.thephpleague.com/2.4/customization/inline-parsing/
     */
    'inline_parsers' => [
        // ['parser' => MyCustomInlineParser::class, 'priority' => 0]
    ],
];
