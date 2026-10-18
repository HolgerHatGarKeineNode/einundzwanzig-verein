<?php

namespace App\Console\Commands;

use App\Models\EinundzwanzigPleb;
use App\Models\ProjectProposal;
use App\Support\DescriptionImageReferences;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Deletes description images that no project description has referred to
 * for GRACE_DAYS days.
 *
 * The clock is `custom_properties.unreferenced_since`, not `created_at`: it
 * is set on the first run that finds the image unreferenced and cleared on
 * any run that finds it referenced again. An old image whose line was just
 * deleted therefore gets the full grace period too — with `created_at` it
 * would go the same night, irreversibly. The same period covers an upload
 * on a create form that was never submitted (there is no draft storage).
 *
 * Only references in `project_proposals.description` are visible here. An
 * image linked solely from outside the site (a Nostr post, another website)
 * counts as unreferenced and is pruned.
 */
class PruneDescriptionImages extends Command
{
    public const GRACE_DAYS = 7;

    public const UNREFERENCED_SINCE = 'unreferenced_since';

    protected $signature = 'description-images:prune
                            {--dry-run : List what would be deleted without writing anything}';

    protected $description = 'Delete description images that no project description has referenced for '.self::GRACE_DAYS.' days';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Media first, descriptions second: a reference written in between
        // is then seen, so the race can only spare an image, never delete one.
        $images = Media::query()
            ->where('collection_name', EinundzwanzigPleb::DESCRIPTION_IMAGES)
            ->get();

        $referenced = array_flip($this->referencedUuids());
        $deadline = now()->subDays(self::GRACE_DAYS);

        $marked = 0;
        $cleared = 0;
        $doomed = [];

        foreach ($images as $media) {
            $since = $this->unreferencedSince($media);

            if (isset($referenced[strtolower($media->uuid)])) {
                if ($media->hasCustomProperty(self::UNREFERENCED_SINCE)) {
                    $cleared++;
                    if (! $dryRun) {
                        $media->forgetCustomProperty(self::UNREFERENCED_SINCE)->save();
                    }
                }

                continue;
            }

            if ($since === null) {
                // Missing or unreadable clock: start it now rather than delete.
                $marked++;
                if (! $dryRun) {
                    $media->setCustomProperty(self::UNREFERENCED_SINCE, now()->toIso8601String())->save();
                }

                continue;
            }

            if ($since->lte($deadline)) {
                $doomed[] = $media;
            }
        }

        if ($dryRun) {
            $this->warn('DRY RUN MODE - Nothing will be written or deleted.');
        }

        if ($doomed !== []) {
            $this->table(
                ['ID', 'UUID', 'Owner', 'Unreferenced since'],
                array_map(fn (Media $media) => [
                    $media->id,
                    $media->uuid,
                    $media->model_id,
                    $media->getCustomProperty(self::UNREFERENCED_SINCE),
                ], $doomed)
            );
        }

        if (! $dryRun) {
            foreach ($doomed as $media) {
                $media->delete();
                Log::info('Pruned unreferenced description image', [
                    'media_uuid' => $media->uuid,
                    'owner_id' => $media->model_id,
                    'unreferenced_since' => $media->getCustomProperty(self::UNREFERENCED_SINCE),
                ]);
            }
        }

        $this->info(sprintf(
            '%s %d image(s); %d newly unreferenced, %d referenced again.',
            $dryRun ? 'Would delete' : 'Deleted',
            count($doomed),
            $marked,
            $cleared,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function referencedUuids(): array
    {
        return ProjectProposal::query()
            ->pluck('description')
            ->flatMap(fn (?string $description) => DescriptionImageReferences::extract($description))
            ->unique()
            ->values()
            ->all();
    }

    private function unreferencedSince(Media $media): ?Carbon
    {
        $value = $media->getCustomProperty(self::UNREFERENCED_SINCE);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
