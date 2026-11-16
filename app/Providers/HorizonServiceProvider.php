<?php

namespace App\Providers;

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        // The portal signs in with Nostr, not with Laravel users: the dashboard
        // is for current board members only.
        Gate::define('viewHorizon', function ($user = null): bool {
            $pleb = NostrAuth::user()?->getPleb();

            return $pleb instanceof EinundzwanzigPleb && ! $pleb->isErased() && $pleb->isBoardMember();
        });
    }
}
