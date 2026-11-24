<?php

namespace App\Livewire\Traits;

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;

/**
 * Re-resolves the acting member for a write action.
 *
 * A component's `currentPleb` is part of its snapshot: it names whoever the
 * page was rendered for, and it survives a logout or a session switch in
 * another tab. A write that trusts it acts for that member, even after the
 * session belongs to somebody else. Every write therefore asks the session
 * again and refuses (403) when nobody is logged in or the session's member is
 * not the one the page was rendered for — the page then has to be reloaded.
 *
 * Expects the using component to have a `currentPleb` property.
 */
trait GuardsActingPleb
{
    protected function actingPleb(): EinundzwanzigPleb
    {
        $sessionPleb = NostrAuth::user()?->getPleb();

        abort_unless(
            $sessionPleb instanceof EinundzwanzigPleb
                && $this->currentPleb instanceof EinundzwanzigPleb
                && $sessionPleb->is($this->currentPleb),
            403,
        );

        return $sessionPleb;
    }
}
