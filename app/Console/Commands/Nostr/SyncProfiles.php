<?php

namespace App\Console\Commands\Nostr;

use App\Models\EinundzwanzigPleb;
use App\Traits\NostrFetcherTrait;
use Illuminate\Console\Command;

class SyncProfiles extends Command
{
    use NostrFetcherTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:profiles {--all : Fetch all plebs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        /*
         * GELOESCHTE MITGLIEDER BLEIBEN HIER DRAUSSEN — und diese Stelle ist
         * der Grund, warum der Filter am Model sitzt und nicht in einem
         * Controller.
         *
         * Die Loeschung entfernt den gecachten kind-0-Satz. Damit ERZEUGT sie
         * genau die Bedingung, auf der `whereDoesntHave('profile')` auswaehlt:
         * die anonymisierte Zeile wird ab dann bei JEDEM Lauf mitgenommen und
         * ihr Tombstone-npub an das Relay geschickt — dauerhaft und mit jeder
         * weiteren Loeschung mehr. Ein Datensatz, dessen Person gerade
         * verlangt hat, nicht mehr gefuehrt zu werden, verliesse also
         * regelmaessig das Haus.
         *
         * Auch mit `--all`: es gibt fuer einen Tombstone kein Profil zu holen,
         * der npub ist keiner.
         */
        $query = EinundzwanzigPleb::query()->notErased();

        if (! $this->option('all')) {
            $query->whereDoesntHave('profile');
        }

        $plebs = $query->get();
        $count = $plebs->count();

        $this->info("\n🔄 Syncing profiles...");

        if ($count > 0) {
            $bar = $this->output->createProgressBar($count);
            $bar->start();
            $this->fetchProfile($plebs->pluck('npub')->toArray());

            $bar->finish();
            $this->info("\n✅ Successfully synced $count profiles!");
        } else {
            $this->info('⚡ No profiles to sync!');
        }
    }
}
