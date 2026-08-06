<?php

namespace App\Console\Commands\Nostr;

use App\Models\Event;
use App\Traits\NostrEventRendererTrait;
use Illuminate\Console\Command;

class RenderAllEvents extends Command
{
    use NostrEventRendererTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'render';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $events = Event::query()
            ->get();

        foreach ($events as $event) {
            $this->renderContentToHtml($event);
        }
    }
}
