<?php

use App\Http\Controllers\Api\GetPaidMembers;
use App\Http\Controllers\Api\Nostr\GetProfile;
use Illuminate\Support\Facades\Route;

// NIP-01 kennt genau ein Pubkey-Format: 64 Zeichen Kleinbuchstaben-Hex. Alles andere
// ist kein Pubkey, sondern ein Tippfehler oder ein Scan — und wird hier abgewiesen,
// bevor der Controller laeuft. Gleiches Muster wie `Nostr\GetProfiles`
// (`app/Http/Controllers/Nostr/GetProfiles.php:60`).
Route::get('/nostr/profile/{key}', GetProfile::class)
    ->where('key', '[0-9a-f]{64}');

Route::get('/members/{year}', GetPaidMembers::class);
