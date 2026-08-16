<?php

namespace App\Support;

use Spatie\LaravelMarkdown\MarkdownRenderer as SpatieMarkdownRenderer;

/**
 * Der eine Weg von gespeichertem Markdown zu ausgebbarem HTML.
 *
 * WARUM ES DIESE KLASSE GIBT und nicht bloß einen Aufruf des Spatie-Renderers:
 * Rendern und Sanitisieren gehören hier untrennbar zusammen, und wer sie
 * trennen kann, wird sie irgendwo trennen. Genau das war der Defekt, den
 * `43d518e` schließen musste — an einer Stelle wurde HTML ausgegeben, das
 * durch keinen Filter gelaufen war. Diese Klasse hat nur eine öffentliche
 * Methode, und die tut beides.
 *
 * DIE REIHENFOLGE IST NICHT BELIEBIG. Erst Markdown zu HTML, dann die
 * Allowlist — nicht umgekehrt. Ein Sanitizer auf dem Markdown-Quelltext würde
 * an Zeichen scheitern, die dort völlig legitim sind (`<` in einem Codeblock),
 * und das eigentliche Ziel verfehlen: gefährlich wird erst das erzeugte HTML.
 *
 * ROHES HTML IM MARKDOWN wird dabei zweimal entschärft, und beide Schichten
 * sind Absicht. `config/markdown.php` setzt `html_input => 'escape'`, sodass
 * ein `<script>` im Quelltext als sichtbarer Text ankommt statt als Element;
 * käme diese Einstellung je abhanden, fängt {@see RichTextSanitizer} das
 * Ergebnis trotzdem ab. Eine der beiden Schichten allein wäre eine Annahme
 * über die andere.
 */
class MarkdownRenderer
{
    /**
     * Markdown zu HTML, das ausgegeben werden darf.
     *
     * Das Ergebnis ist für `{!! !!}` bestimmt — es ist die einzige Form, in
     * der eine Beschreibung die Anwendung verlassen soll.
     */
    public function toSafeHtml(?string $markdown): string
    {
        if ($markdown === null || trim($markdown) === '') {
            return '';
        }

        $html = app(SpatieMarkdownRenderer::class)->toHtml($markdown);

        return (string) (new RichTextSanitizer)->sanitize($html);
    }
}
