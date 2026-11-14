<?php

namespace App\Livewire\Traits;

use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Gate;

/**
 * How the member proposal pages ask "who is looking, and may they?".
 *
 * Nothing here is public and nothing is stored on the component: the viewer
 * is resolved from the Nostr session on every request, so neither a member
 * record nor a remembered permission travels through the Livewire snapshot.
 */
trait WithMemberProposalAccess
{
    protected function viewer(): ?EinundzwanzigPleb
    {
        $pleb = NostrAuth::user()?->getPleb();

        return $pleb instanceof EinundzwanzigPleb ? $pleb : null;
    }

    protected function viewerCan(string $ability, mixed $arguments = MemberProposal::class): bool
    {
        return Gate::forUser(NostrAuth::user())->allows($ability, $arguments);
    }

    protected function authorizeViewer(string $ability, mixed $arguments = MemberProposal::class): void
    {
        Gate::forUser(NostrAuth::user())->authorize($ability, $arguments);
    }
}
