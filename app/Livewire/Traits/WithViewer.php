<?php

namespace App\Livewire\Traits;

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Illuminate\Support\Facades\Gate;

/**
 * How a page asks "who is looking, and may they?".
 *
 * Nothing here is stored on the component: the viewer is resolved from the
 * Nostr session on every request, so neither a member record nor a
 * remembered permission travels through the Livewire snapshot. An erased
 * member is nobody — the record survives the erasure, the person does not.
 */
trait WithViewer
{
    /**
     * The member behind the session, or null (guest, no member record, or
     * an erased one).
     */
    protected function viewer(): ?EinundzwanzigPleb
    {
        $pleb = NostrAuth::user()?->getPleb();

        return $pleb !== null && ! $pleb->isErased() ? $pleb : null;
    }

    protected function viewerCan(string $ability, mixed $arguments = []): bool
    {
        return Gate::forUser(NostrAuth::user())->allows($ability, $arguments);
    }

    protected function authorizeViewer(string $ability, mixed $arguments = []): void
    {
        Gate::forUser(NostrAuth::user())->authorize($ability, $arguments);
    }

    /**
     * Is the viewer on the board (the `board` gate)? For what a page
     * renders; every board action still goes through `boardActor()`.
     */
    protected function viewerIsBoard(): bool
    {
        return $this->viewerCan('board');
    }

    /**
     * The board member behind the session, or 403. Asked on every request,
     * never remembered on the component.
     */
    protected function boardActor(): EinundzwanzigPleb
    {
        $pleb = $this->viewer();

        abort_unless($pleb !== null && $this->viewerIsBoard(), 403);

        return $pleb;
    }

    /**
     * The member a write acts for: the viewer, and only if it is the member
     * the page was rendered for, else 403.
     *
     * A component's `currentPleb` is part of its snapshot: it names whoever
     * the page was rendered for, and it survives a logout or a session
     * switch in another tab. A write that trusts it acts for that member,
     * even after the session belongs to somebody else. Every write therefore
     * asks the session again and refuses when nobody is logged in or the
     * session's member is not the one the page was rendered for — the page
     * then has to be reloaded.
     *
     * Expects the using component to have a `currentPleb` property.
     */
    protected function actingPleb(): EinundzwanzigPleb
    {
        $sessionPleb = $this->viewer();

        abort_unless(
            $sessionPleb !== null
                && $this->currentPleb instanceof EinundzwanzigPleb
                && $sessionPleb->is($this->currentPleb),
            403,
        );

        return $sessionPleb;
    }
}
