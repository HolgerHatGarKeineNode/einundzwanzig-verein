<?php

namespace App\Console\Commands;

use App\Models\ProjectProposal;
use App\Support\RichTextMarkdownNormalizer;
use App\Support\RichTextSanitizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Since `RichTextMarkdownNormalizer::normalize()` ends in
 * {@see RichTextSanitizer}, this command is also the backfill for rows written
 * before that sanitizing existed: running it strips event handlers,
 * `javascript:`/`data:` URLs and non-allowlisted elements from the stored
 * value.
 *
 * IT IS NOT WHAT PROTECTS READERS, though, and must not be treated as such.
 * The output side (`ProjectProposal::safeDescription()`) sanitizes on every
 * render and therefore covers rows this command has never touched — including
 * any written between a deploy and the next run. This is hygiene for the
 * database; the guarantee lives at the point of output.
 */
#[Signature('project-proposals:normalize-descriptions
    {--dry-run : Show what would change without writing to the database}
    {--id=* : Limit to specific proposal IDs}
    {--show-diff : Print a short before/after preview for every change}')]
#[Description('Normalize project proposal descriptions so all rows contain clean HTML (converts legacy plain-text and mixed Markdown/HTML content) and strip any active HTML they carry.')]
class NormalizeProjectProposalDescriptions extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $showDiff = (bool) $this->option('show-diff');
        $ids = array_filter((array) $this->option('id'));

        $normalizer = new RichTextMarkdownNormalizer;

        $query = ProjectProposal::query()->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No project proposals to process.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d project proposal description(s)%s.',
            $dryRun ? 'Analyzing' : 'Normalizing',
            $total,
            $dryRun ? ' (dry-run)' : '',
        ));

        $changed = 0;
        $unchanged = 0;
        $failed = 0;
        $skipped = 0;

        $query->lazy()->each(function (ProjectProposal $proposal) use ($normalizer, $dryRun, $showDiff, &$changed, &$unchanged, &$failed, &$skipped): void {
            $original = (string) ($proposal->description ?? '');

            try {
                $normalized = (string) ($normalizer->normalize($original) ?? '');
            } catch (\Throwable $exception) {
                $failed++;
                $this->error(sprintf('#%d %s — normalization failed: %s', $proposal->id, $proposal->name, $exception->getMessage()));

                return;
            }

            if ($original === $normalized) {
                $unchanged++;

                return;
            }

            /*
             * DER TEXT MUSS DERSELBE BLEIBEN. Dieser Befehl formt Auszeichnung
             * um — aus Klartext werden Absätze, aus rohem HTML wird
             * allowlist-gefiltertes HTML. Was er NICHT darf, ist ein Wort
             * verlieren: die Beschreibung ist der Antrag eines Mitglieds, und
             * ein stillschweigend gekürzter Antrag ist schlimmer als ein
             * unschön dargestellter.
             *
             * Verglichen wird deshalb der reine Textgehalt, nicht das Markup.
             * Fällt er auseinander, wird die Zeile ÜBERSPRUNGEN und gemeldet,
             * statt geschrieben — der Befehl bricht nicht ab, damit die
             * übrigen Zeilen trotzdem durchlaufen, aber er endet mit einem
             * Fehlercode, sodass niemand einen solchen Lauf für sauber hält.
             *
             * Die eine Ausnahme, die kein Verlust ist: Wenn der Sanitizer ein
             * aktives Element wirft, verschwindet dessen Inhalt mit — genau
             * das ist der Zweck. Solche Zeilen tauchen hier auf und gehören
             * angesehen, nicht automatisch geschrieben.
             */
            $lost = $this->lostWords($original, $normalized);

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

            $changed++;
            $this->line(sprintf('<fg=yellow>~</> #%d %s', $proposal->id, $proposal->name));

            if ($showDiff) {
                $this->line('  <fg=red>- '.$this->preview($original).'</>');
                $this->line('  <fg=green>+ '.$this->preview($normalized).'</>');
            }

            if (! $dryRun) {
                $proposal->description = $normalized;
                $proposal->saveQuietly();
            }
        });

        $this->newLine();
        $this->info(sprintf(
            'Done. Changed: %d, unchanged: %d, skipped: %d, failed: %d%s',
            $changed,
            $unchanged,
            $skipped,
            $failed,
            $dryRun ? ' (no writes performed)' : '',
        ));

        // Übersprungene Zeilen sind kein Erfolg: sie brauchen einen Menschen.
        return $failed > 0 || $skipped > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Die Wörter, die aus einer Beschreibung verschwinden würden.
     *
     * GEPRÜFT WIRD VERLUST, NICHT GLEICHHEIT, und der Unterschied ist der
     * ganze Nutzen dieser Methode. Ein Vergleich der Textgehalte Zeichen für
     * Zeichen war die erste Fassung und war unbrauchbar: er schlug bei drei
     * von fünf echten Anträgen an, weil `- Punkt` zu `<li>Punkt</li>` wird und
     * `` `wss://…` `` zu `<code>wss://…</code>`. Dabei verschwinden der
     * Listenstrich und die Backticks — Auszeichnung, kein Text, und ihre
     * Umwandlung in Struktur ist genau der Zweck dieses Befehls. Ein Wächter,
     * der die beabsichtigte Arbeit als Schaden meldet, wird nach dem zweiten
     * Mal ignoriert.
     *
     * Gezählt wird deshalb, ob jedes Wort noch so oft vorkommt wie zuvor.
     * Satzzeichen, Marker und Leerraum dürfen sich beliebig ändern; ein Wort
     * darf nicht seltener werden. Damit bleibt genau der Fall hängen, um den
     * es geht: Der Sanitizer wirft ein aktives Element samt seinem sichtbaren
     * Inhalt — richtig für die Sicherheit, aber eine Entscheidung, die ein
     * Mensch treffen muss, nicht ein Stapellauf.
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

        $lost = [];

        foreach ($words($before) as $word => $count) {
            $remaining = $words($after)[$word] ?? 0;

            if ($remaining < $count) {
                $lost[] = $word;
            }
        }

        return $lost;
    }

    private function preview(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return mb_strimwidth($collapsed, 0, 140, '…');
    }
}
