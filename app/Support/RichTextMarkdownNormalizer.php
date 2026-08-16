<?php

namespace App\Support;

use DOMDocument;
use Spatie\LaravelMarkdown\MarkdownRenderer;

/**
 * Converts Tiptap/flux:editor HTML that contains literal Markdown syntax
 * (e.g. from a plain-text paste) into proper structured HTML.
 *
 * The flux:editor wraps pasted plain text in <p> tags line by line, so
 * pasted Markdown like `# Heading` ends up as `<p># Heading</p>` and never
 * gets rendered as a real heading. This normalizer detects that situation
 * and runs the extracted text through a Markdown renderer.
 *
 * If the HTML already contains structural elements produced by the editor
 * toolbar (headings, lists, blockquotes, code blocks), the content is left
 * untouched so real rich-text edits are preserved.
 */
class RichTextMarkdownNormalizer
{
    /**
     * @var array<int, string> HTML tags that indicate the user already
     *                         used the editor toolbar for structure.
     */
    private const STRUCTURAL_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'blockquote', 'pre', 'table',
    ];

    /**
     * Regex fragments that indicate plain-text Markdown syntax.
     *
     * @var array<int, string>
     */
    private const MARKDOWN_LINE_PATTERNS = [
        '/^\s{0,3}#{1,6}\s+\S/m',          // ATX headings
        '/^\s{0,3}[-*+]\s+\S/m',           // bullet list
        '/^\s{0,3}\d{1,9}[.)]\s+\S/m',     // ordered list
        '/^\s{0,3}>\s?/m',                 // blockquote
        '/^\s{0,3}```/m',                  // fenced code block
    ];

    /**
     * Render a raw Markdown string directly to HTML using the same
     * configuration as normalize(), sanitized the same way.
     *
     * Reached from the paste listener in the editor, so its output goes back
     * into the field a user then saves — same destination, same treatment.
     */
    public function toHtml(string $markdown): string
    {
        return (string) (new RichTextSanitizer)->sanitize(trim($this->renderer()->toHtml($markdown)));
    }

    /**
     * Normalize editor HTML, and sanitize whatever comes out of it.
     *
     * THE SANITIZING STEP IS NOT AN AFTERTHOUGHT HERE, it is the reason every
     * return path below goes through one function. This method has three
     * branches and two of them hand the input straight back — the structural
     * one and the "does not look like Markdown" one — so for most real input
     * the Markdown renderer never runs. Anything that relied on the renderer
     * to strip active HTML therefore did not apply, and measurably did not: a
     * plain `<script>` survived `normalize()` untouched and was written to the
     * database, from where `{!! !!}` on the detail page executed it for every
     * visitor.
     *
     * Sanitizing at the END of this method rather than inside each branch is
     * deliberate: a branch added later cannot forget it. It is also only HALF
     * of the fix — the other half is at the point of output, because rows
     * written before this existed have never been through here. See
     * {@see RichTextSanitizer} and `ProjectProposal::safeDescription()`.
     */
    public function normalize(?string $html): ?string
    {
        return (new RichTextSanitizer)->sanitize($this->structure($html));
    }

    /**
     * The normalization proper: turn pasted Markdown into real HTML, and leave
     * genuine rich text alone.
     */
    private function structure(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        // Pure plain text (no HTML tags at all): render directly as Markdown
        // so newline-separated paragraphs, headings, links, etc. become HTML.
        if (! $this->containsHtmlTags($html)) {
            $rendered = trim($this->renderer()->toHtml($html));

            return $rendered === '' ? $html : $rendered;
        }

        // Already structured via the editor toolbar: leave untouched.
        if ($this->containsStructuralHtml($html)) {
            return $html;
        }

        // Paragraph-only HTML: if the inner text looks like Markdown
        // (pasted plain text wrapped in <p> by Tiptap), extract and render.
        $plainText = $this->extractPlainTextPreservingLineBreaks($html);

        if (! $this->looksLikeMarkdown($plainText)) {
            return $html;
        }

        $rendered = trim($this->renderer()->toHtml($plainText));

        return $rendered === '' ? $html : $rendered;
    }

    private function containsHtmlTags(string $input): bool
    {
        return preg_match('/<[a-z!\/][^>]*>/i', $input) === 1;
    }

    private function renderer(): MarkdownRenderer
    {
        $config = config('markdown');

        return new MarkdownRenderer(
            commonmarkOptions: $config['commonmark_options'] ?? [],
            highlightCode: $config['code_highlighting']['enabled'] ?? false,
            highlightTheme: $config['code_highlighting']['theme'] ?? 'github-light',
            cacheStoreName: $config['cache_store'] ?? null,
            renderAnchors: $config['add_anchors_to_headings'] ?? false,
            renderAnchorsAsLinks: $config['render_anchors_as_links'] ?? false,
            extensions: $config['extensions'] ?? [],
            blockRenderers: $config['block_renderers'] ?? [],
            inlineRenderers: $config['inline_renderers'] ?? [],
            inlineParsers: $config['inline_parsers'] ?? [],
            cacheDuration: $config['cache_duration'] ?? null,
        );
    }

    private function containsStructuralHtml(string $html): bool
    {
        foreach (self::STRUCTURAL_TAGS as $tag) {
            if (stripos($html, '<'.$tag) !== false) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeMarkdown(string $text): bool
    {
        foreach (self::MARKDOWN_LINE_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function extractPlainTextPreservingLineBreaks(string $html): string
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementsByTagName('div')->item(0);

        if ($root === null) {
            return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        }

        $blocks = [];

        foreach ($root->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE && strtolower($child->nodeName) === 'p') {
                $blocks[] = $this->nodeToMarkdown($child);
            } else {
                $blocks[] = trim($this->nodeToMarkdown($child));
            }
        }

        return trim(implode("\n\n", array_filter($blocks, static fn ($line) => $line !== '')));
    }

    /**
     * Walk a DOM node and produce a Markdown-equivalent string for its
     * contents, preserving inline formatting (strong, em, code, links,
     * images) and converting <br> to newlines.
     */
    private function nodeToMarkdown(\DOMNode $node): string
    {
        $buffer = '';

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $buffer .= $child->textContent ?? '';

                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            /** @var \DOMElement $child */
            $tag = strtolower($child->nodeName);

            switch ($tag) {
                case 'br':
                    $buffer .= "\n";
                    break;

                case 'strong':
                case 'b':
                    $inner = $this->nodeToMarkdown($child);
                    $buffer .= $inner === '' ? '' : '**'.$inner.'**';
                    break;

                case 'em':
                case 'i':
                    $inner = $this->nodeToMarkdown($child);
                    $buffer .= $inner === '' ? '' : '*'.$inner.'*';
                    break;

                case 's':
                case 'del':
                case 'strike':
                    $inner = $this->nodeToMarkdown($child);
                    $buffer .= $inner === '' ? '' : '~~'.$inner.'~~';
                    break;

                case 'code':
                    $buffer .= '`'.($child->textContent ?? '').'`';
                    break;

                case 'a':
                    $text = $this->nodeToMarkdown($child);
                    $href = $child->getAttribute('href');
                    if ($href === '') {
                        $buffer .= $text;
                    } elseif (trim($text) === '' || $text === $href) {
                        $buffer .= $href;
                    } else {
                        $buffer .= '['.$text.']('.$href.')';
                    }
                    break;

                case 'img':
                    $src = $child->getAttribute('src');
                    $alt = $child->getAttribute('alt');
                    if ($src !== '') {
                        $buffer .= '!['.$alt.']('.$src.')';
                    }
                    break;

                default:
                    $buffer .= $this->nodeToMarkdown($child);
                    break;
            }
        }

        return $buffer;
    }
}
