<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The allowlist every piece of user-authored rich text passes through.
 *
 * WHY THIS EXISTS AT ALL. `ProjectProposal::$description` is written by a
 * Tiptap editor and rendered with `{!! !!}`, which is the one construction in
 * Blade that does not escape. Between the two sat nothing: the form request
 * validates the field as `string`, and `RichTextMarkdownNormalizer` returns
 * HTML unchanged in both of its pass-through branches, so the Markdown
 * renderer — and with it `DisallowedRawHtmlExtension` — never saw the value.
 * Measured end to end before this class was written: a stored
 * `<script>` executed for every visitor of the public detail page, without
 * any login.
 *
 * WHAT MAKES THAT WORSE THAN A DEFACEMENT. The detail page carries the board's
 * own actions — `handleApprove`, `handleNotApprove`, `recordPayout`,
 * `revertPayout`. Every public Livewire method is callable as
 * `$wire.<name>()` from injected script, and the policies behind them check
 * the VIEWER. A board member opening a poisoned proposal would approve it, or
 * book a payout, without a click. `window.nostr` lives in the same context.
 *
 * AN ALLOWLIST, NOT A DENYLIST, and not a hand-rolled one. A denylist has to
 * enumerate every way of writing a script — `<svg onload>`, `<div onclick>`,
 * `<a href="javascript:">`, mXSS through mismatched parsers — and it is wrong
 * the day a browser learns a new one. `symfony/html-sanitizer` parses the
 * document and rebuilds it from elements and attributes that are named here;
 * anything not named simply does not survive, including every `on*` handler,
 * because handlers are attributes and no attribute is allowed unless listed.
 *
 * THE LIST IS THE EDITOR'S OWN VOCABULARY. Everything `flux:editor` can
 * produce is here, so a legitimate proposal is not altered by passing through.
 * That is the property to preserve when this list is edited: adding an element
 * the editor cannot produce widens the surface for nothing.
 */
class RichTextSanitizer
{
    /**
     * The elements the editor produces, and no others.
     *
     * @var list<string>
     */
    private const ALLOWED_ELEMENTS = [
        'p', 'br', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'blockquote', 'pre', 'code',
        'strong', 'b', 'em', 'i', 's', 'del', 'u',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'span', 'div',
    ];

    /**
     * The two elements that carry a URL, and the only attributes that survive
     * anywhere in the document.
     *
     * `title` and `alt` are text, `href` and `src` are restricted to `http`
     * and `https` below. Notably absent: `style`, which can position an
     * element over a button and turn any click into a click on something else,
     * and `class`, which the editor does not need and which would let a
     * proposal borrow the styling of the association's own controls.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
    ];

    /**
     * Sanitize a block of user-authored HTML.
     *
     * An empty result for a non-empty input is a legitimate outcome — it means
     * the input consisted only of things that are not allowed — and is
     * returned as-is rather than falling back to the original. A fallback
     * would make the one input that most needs filtering the one input that
     * skips it.
     */
    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return trim($this->sanitizer()->sanitize($html));
    }

    private function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            /*
             * Both schemes, and no others. `javascript:` is the obvious one to
             * keep out; `data:` matters just as much, because `data:text/html`
             * in an `href` is a same-origin-ish document of the attacker's
             * choosing in some browsers, and `mailto:`/`tel:` are simply not
             * things this editor offers.
             */
            ->allowLinkSchemes(['http', 'https'])
            ->allowMediaSchemes(['http', 'https'])
            /*
             * Relative URLs are refused rather than resolved. A proposal has
             * no business linking into this application by path — and a
             * relative `src` would let one probe internal routes with the
             * reader's own session attached.
             */
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false);

        foreach (self::ALLOWED_ELEMENTS as $element) {
            $config = $config->allowElement($element);
        }

        foreach (self::ALLOWED_ATTRIBUTES as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        return new HtmlSanitizer($config);
    }
}
