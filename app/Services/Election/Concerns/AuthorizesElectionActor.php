<?php

namespace App\Services\Election\Concerns;

use App\Auth\NostrUser;
use App\Models\EinundzwanzigPleb;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Ask `ElectionPolicy` on behalf of the member who acts — the same gate a
 * request would pass, built from the stored member, not from the instance the
 * caller holds.
 */
trait AuthorizesElectionActor
{
    /**
     * @throws AuthorizationException
     */
    protected function authorizeActor(EinundzwanzigPleb $actor, string $ability, Model $subject): void
    {
        Gate::forUser(new NostrUser((string) $actor->pubkey))->authorize($ability, $subject);
    }
}
