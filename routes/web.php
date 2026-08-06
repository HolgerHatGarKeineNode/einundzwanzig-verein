<?php

use App\Http\Controllers\BtcPayWebhookController;
use App\Http\Controllers\Nostr\GetProfiles;
use App\Support\NostrAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

Route::redirect('/', '/association/profile');

Route::get('dl/{media}', function (Media $media, Request $request) {
    return Storage::disk($media->disk)->download(
        $media->getPathRelativeToRoot(),
        $media->file_name
    );
})
    ->whereNumber('media')
    ->name('dl')
    ->middleware('signed');

Route::get('media/{media}', function (Media $media, Request $request) {
    $conversion = $request->query('conversion');

    if ($conversion && $media->hasGeneratedConversion($conversion)) {
        $path = $media->getPathRelativeToRoot($conversion);
    } else {
        $path = $media->getPathRelativeToRoot();
    }

    return Storage::disk($media->disk)->response(
        $path,
        $media->file_name,
        [
            'Content-Type' => $media->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ]
    );
})
    ->whereNumber('media')
    ->name('media.signed')
    ->middleware('signed');

Route::post('webhooks/btcpay', BtcPayWebhookController::class)->name('webhooks.btcpay');

// Profil-Seed der Chat-Insel: Pfad + Antwortform sind im Package fest verdrahtet
// (`js/profiles.ts` → `GET <base>/nostr/profiles?pubkeys=a,b,c`, Web: base = ''),
// deshalb hier und nicht in routes/api.php.
//
// Offen für alle, auch Gäste: kind-0 ist auf Nostr öffentlich. Die Drosselung ist
// hier der eigentliche Schutz — sie begrenzt die serverseitige Relay-Arbeit, die
// ein Aufruf auslöst. Für Angemeldete zählt sie pro Pubkey, sonst pro IP, damit
// eine geteilte Adresse nicht alle gemeinsam ausbremst.
//
// ACHTUNG bei einer späteren Aktivierung des Package-eigenen GroupServiceProvider
// (aktuell per extra.laravel.dont-discover abgeschaltet): Der belegt denselben
// Namensraum mit /nostr/challenge|login|logout
// (vendor/einundzwanzig/group/routes/group.php:17-19). /nostr/profiles kollidiert
// heute mit keiner davon, das ist aber Zufall und keine Zusage.
Route::get('nostr/profiles', GetProfiles::class)
    ->middleware('throttle:nostr-profiles')
    ->name('nostr.profiles');

Route::post('logout', function () {
    NostrAuth::logout();
    Session::flush();

    return redirect('/');
})->name('logout');

// Association Routes
Route::livewire('/association/profile', 'association.profile')->name('association.profile');
Route::livewire('/association/benefits', 'association.benefits')->name('association.benefits');

Route::livewire('/association/members/admin', 'association.members.admin')->name('association.members.admin');

Route::livewire('/association/news', 'association.news')->name('association.news');

Route::livewire('/association/project-support', 'association.project-support.index')->name('association.projectSupport');
Route::livewire('/association/project-support/create', 'association.project-support.form.create')->name('association.projectSupport.create');
Route::livewire('/association/project-support/{projectProposal:slug}', 'association.project-support.show')->name('association.projectSupport.item');
Route::livewire('/association/project-support/edit/{projectProposal:slug}', 'association.project-support.form.edit')->name('association.projectSupport.edit');
