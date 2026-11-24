<?php

namespace App\Http\Controllers;

use App\Support\NostrAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Ends the Nostr session. The session is invalidated (new id, data gone) and
 * the CSRF token regenerated, so neither a captured session id nor a token
 * from the signed-in page outlives the logout.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        NostrAuth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
