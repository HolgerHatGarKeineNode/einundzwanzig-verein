<?php

namespace App\Console\Commands\Nostr;

use App\Services\Nostr\ProfileFetcher;
use Illuminate\Console\Command;

class FetchProfile extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nostr:profile {--pubkey= : Hex pubkey or npub}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read one kind-0 profile from the profile relays and store it';

    /**
     * Execute the console command.
     */
    public function handle(ProfileFetcher $profiles): int
    {
        $pubkey = $this->option('pubkey');

        if (empty($pubkey)) {
            $this->error('Please provide a pubkey');

            return self::FAILURE;
        }

        $this->line('profiles_stored='.$profiles->fetch([$pubkey]));

        return self::SUCCESS;
    }
}
