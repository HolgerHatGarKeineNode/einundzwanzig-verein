<?php

namespace App\Console\Commands;

use App\Models\ProjectProposal;
use App\Support\MarkdownRenderer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

/**
 * Die einmalige Umstellung von `project_proposals.description`: HTML raus,
 * Markdown rein.
 *
 * DER ANLASS ist der Wechsel des Eingabefelds. Der frühere `flux:editor`
 * (Tiptap) speicherte HTML; das Markdown-Feld, das ihn ersetzt, speichert den
 * Rohtext und rendert erst bei der Ausgabe. Beides gleichzeitig in einer
 * Spalte ginge nicht gut: Bestehendes HTML würde vom Markdown-Renderer
 * escaped und stünde als sichtbares `&lt;p&gt;` auf der Seite.
 *
 * WAS IHN VON EINEM GEWÖHNLICHEN BATCH UNTERSCHEIDET, ist die Gegenprobe. Er
 * wandelt nicht nur, er RENDERT DAS ERGEBNIS ZURÜCK und vergleicht es mit dem
 * Ausgangs-HTML — Wort für Wort. Fällt dabei etwas heraus, wird die Zeile
 * übersprungen, gemeldet und der Lauf endet mit Fehlercode. Eine
 * HTML-nach-Markdown-Wandlung ist verlustbehaftet und kennt bekannte Löcher
 * (`league/html-to-markdown` hat z. B. gar keinen Konverter für `<s>`/`<del>`
 * — die kämen als rohes Tag durch und würden anschließend escaped auf der
 * Seite landen). Genau solche Fälle darf kein Stapellauf still entscheiden.
 *
 * IDEMPOTENT: Eine Beschreibung ohne HTML-Tags ist bereits Markdown und wird
 * übergangen. Ein zweiter Lauf ändert deshalb nichts.
 */
#[Signature('project-proposals:to-markdown
    {--dry-run : Zeigen, was sich ändern würde, ohne zu schreiben}
    {--id=* : Auf bestimmte IDs beschränken}
    {--show-diff : Vorher/Nachher je Änderung ausgeben}')]
#[Description('Wandelt gespeichertes HTML in den Projektbeschreibungen einmalig nach Markdown um — mit Rück-Rendern als Gegenprobe.')]
class ConvertProjectProposalDescriptionsToMarkdown extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $showDiff = (bool) $this->option('show-diff');
        $ids = array_filter((array) $this->option('id'));

        $query = ProjectProposal::query()->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        if ((clone $query)->count() === 0) {
            $this->warn('Keine Förderanträge vorhanden.');

            return self::SUCCESS;
        }

        $converter = new HtmlConverter([
            /*
             * `atx`, sonst schriebe der Konverter H1/H2 als Unterstreichungen
             * mit === und ---. Beides ist gültiges Markdown, aber # ist das,
             * was ein Mensch in diesem Feld tippt und was die Werkzeugleiste
             * einfügt — die Datenbank soll aussehen wie das, was jemand
             * geschrieben hätte.
             */
            'header_style' => 'atx',

            /*
             * Ein <br> wird sonst zu zwei Leerzeichen am Zeilenende — einem
             * unsichtbaren Zeichen, das jeder spätere Editor wegputzt und
             * damit den Umbruch verliert. Der Backslash ist sichtbar und
             * überlebt.
             */
            'hard_break' => true,

            /*
             * Tags ohne Markdown-Entsprechung sollen NICHT als rohes HTML
             * stehen bleiben. Sie würden vom Renderer escaped und als
             * sichtbarer Tag-Text auf der Seite landen. Was hier verloren
             * geht, fängt die Wortprüfung unten ab.
             */
            'strip_tags' => true,
        ]);

        $renderer = new MarkdownRenderer;

        $converted = 0;
        $alreadyMarkdown = 0;
        $skipped = 0;
        $failed = 0;

        $query->lazy()->each(function (ProjectProposal $proposal) use ($converter, $renderer, $dryRun, $showDiff, &$converted, &$alreadyMarkdown, &$skipped, &$failed): void {
            $html = (string) ($proposal->description ?? '');

            if (trim($html) === '' || ! $this->containsHtml($html)) {
                $alreadyMarkdown++;

                return;
            }

            try {
                $markdown = trim($converter->convert($this->preserveInlineBreaks($html)));
            } catch (Throwable $exception) {
                $failed++;
                $this->error(sprintf('#%d %s — Wandlung fehlgeschlagen: %s', $proposal->id, $proposal->name, $exception->getMessage()));

                return;
            }

            /*
             * DIE GEGENPROBE: Das frische Markdown wieder rendern und mit dem
             * Ausgangs-HTML vergleichen. Nicht Zeichen für Zeichen — die
             * Auszeichnung darf sich unterscheiden, das ist der Zweck —,
             * sondern über die Wörter. Verschwindet eines, bleibt die Zeile,
             * wie sie war.
             */
            $lost = $this->lostWords($html, $renderer->toSafeHtml($markdown));

            if ($lost !== []) {
                $skipped++;
                $this->error(sprintf(
                    '#%d %s — ÜBERSPRUNGEN, Text ginge verloren. Fehlende Wörter: %s',
                    $proposal->id,
                    $proposal->name,
                    $this->preview(implode(', ', $lost)),
                ));

                return;
            }

            $converted++;
            $this->line(sprintf('<fg=yellow>~</> #%d %s', $proposal->id, $proposal->name));

            if ($showDiff) {
                $this->line('  <fg=red>- '.$this->preview($html).'</>');
                $this->line('  <fg=green>+ '.$this->preview($markdown).'</>');
            }

            if (! $dryRun) {
                $proposal->description = $markdown;
                $proposal->saveQuietly();
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Fertig. Gewandelt: %d, schon Markdown: %d, übersprungen: %d, fehlgeschlagen: %d%s',
            $converted,
            $alreadyMarkdown,
            $skipped,
            $failed,
            $dryRun ? ' (nichts geschrieben)' : '',
        ));

        return $failed > 0 || $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Zeilenumbrüche im Fließtext zu `<br>` machen, bevor der Konverter sie
     * verliert.
     *
     * GEMESSEN AN EINEM ECHTEN ANTRAG, den die Wortprüfung zu Recht gestoppt
     * hat. Im Text stand
     *
     *     …<a href="…">#einundzwanzigwrite</a>
     *     damit wir euch auch finden
     *
     * und `league/html-to-markdown` verschluckte den Umbruch direkt hinter dem
     * Inline-Element — heraus kam `…einundzwanzigwrite)damit wir…`, zwei
     * zusammengeklebte Wörter. `hard_break` ändert daran nichts, beide
     * Einstellungen liefern dasselbe (nachgemessen).
     *
     * Ersetzt wird nur ein `\n`, dem KEIN `<` und kein Leerraum folgt — also
     * genau die Umbrüche mitten im Text. Die Umbrüche zwischen Blöcken
     * (`</p>\n<p>`) bleiben unangetastet; sie sind Formatierung der
     * Auszeichnung und kein Inhalt.
     */
    private function preserveInlineBreaks(string $html): string
    {
        return preg_replace('/\n(?![\s<])/u', '<br>', $html) ?? $html;
    }

    /**
     * Trägt dieser Wert überhaupt HTML?
     *
     * Bewusst grob: Ein einzelnes `<` in einem Satz („5 < 10") ist kein Tag,
     * ein `<p>` oder `<a href=…>` schon. Falsch-negative kosten hier nichts —
     * eine Zeile ohne erkanntes HTML gilt als bereits fertig und bleibt
     * unangetastet.
     */
    private function containsHtml(string $value): bool
    {
        return preg_match('/<\/?[a-z][a-z0-9]*(\s[^>]*)?>/i', $value) === 1;
    }

    /**
     * Wörter, die beim Hin- und Zurückwandeln verloren gingen.
     *
     * @return list<string>
     */
    private function lostWords(string $before, string $after): array
    {
        $words = static function (string $html): array {
            $spaced = str_replace(['<br>', '<br/>', '<br />', '</p>', '</li>', '</h1>', '</h2>', '</h3>'], ' ', $html);
            $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5);

            preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}_-]+/u', mb_strtolower($text), $matches);

            return array_count_values($matches[0]);
        };

        $after = $words($after);
        $lost = [];

        foreach ($words($before) as $word => $count) {
            if (($after[$word] ?? 0) < $count) {
                $lost[] = $word;
            }
        }

        return $lost;
    }

    private function preview(string $value): string
    {
        return mb_strimwidth(preg_replace('/\s+/', ' ', trim($value)) ?? '', 0, 140, '…');
    }
}
