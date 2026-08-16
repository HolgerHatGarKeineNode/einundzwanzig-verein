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

        $query->lazy()->each(function (ProjectProposal $proposal) use ($normalizer, $dryRun, $showDiff, &$changed, &$unchanged, &$failed): void {
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
            'Done. Changed: %d, unchanged: %d, failed: %d%s',
            $changed,
            $unchanged,
            $failed,
            $dryRun ? ' (no writes performed)' : '',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function preview(string $value): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return mb_strimwidth($collapsed, 0, 140, '…');
    }
}
