<?php

namespace App\Http\Controllers\Api\Nostr;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Traits\NostrFetcherTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/nostr/profile/{key} — liefert das gespiegelte kind-0-Profil zu EINEM Pubkey.
 *
 * Lesend, und zwar ausschliesslich. Bis 2026-08-06 legte dieser unauthentifizierte GET
 * per `firstOrCreate()` einen `EinundzwanzigPleb` an — jeder beliebige Hex-String erzeugte
 * damit eine Zeile in der Mitgliedertabelle, die die Vorstandsuebersicht und den
 * CSV-Export speist. Ein GET schreibt hier nicht mehr; der Mitgliedersatz entsteht
 * ausschliesslich auf dem angemeldeten Pfad.
 *
 * `{key}` ist per Route-Constraint auf 64 Zeichen Kleinbuchstaben-Hex begrenzt
 * (`routes/api.php`, gleiche Form wie `Nostr\GetProfiles`): NIP-01 kennt kein anderes
 * Pubkey-Format, und die Pruefung greift VOR dem Controller. Junk erreicht damit weder
 * die Datenbank noch den Relay-Abruf, sondern faellt als 404 aus dem Router.
 */
class GetProfile extends Controller
{
    use NostrFetcherTrait;

    public function __invoke(string $key, Request $request): Profile|JsonResponse
    {
        $profile = Profile::query()->where('pubkey', $key)->first();

        if (! $profile) {
            $this->fetchProfile([$key]);

            $profile = Profile::query()->where('pubkey', $key)->first();
        }

        if (! $profile) {
            return response()->json(['message' => 'Profile not found'], 404);
        }

        return $profile;
    }
}
