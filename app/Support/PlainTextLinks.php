<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Prints a plain-text message as HTML: everything escaped, line breaks kept
 * by the caller's CSS, and http(s) URLs turned into links that open in a new
 * tab. Nothing else in the text becomes markup — a direct message is plain
 * text (see App\Enums\DmMessageType), so a `<script>` in it stays visible
 * text.
 */
final class PlainTextLinks
{
    /** Only http and https become links; `javascript:` and friends stay text. */
    private const string URL = '~https?://[^\s<>"\'„“”«»]+~iu';

    /** Sentence punctuation that follows a URL rather than belonging to it. */
    private const string TRAILING = '.,;:!?)]}\'"';

    public static function toHtml(string $text): HtmlString
    {
        $html = '';
        $offset = 0;

        preg_match_all(self::URL, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$url, $position]) {
            $link = rtrim($url, self::TRAILING);

            $html .= e(substr($text, $offset, $position - $offset));
            $html .= '<a href="'.e($link).'" target="_blank" rel="noopener noreferrer">'.e($link).'</a>';

            $offset = $position + strlen($link);
        }

        return new HtmlString($html.e(substr($text, $offset)));
    }
}
