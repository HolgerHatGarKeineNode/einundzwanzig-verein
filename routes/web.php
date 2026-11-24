<?php

use App\Http\Controllers\BtcPayWebhookController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\Nostr\GetProfiles;
use App\Http\Controllers\Nostr\ProfileCard;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/association/profile');

Route::get('dl/{media}', [MediaController::class, 'download'])
    ->whereNumber('media')
    ->name('dl')
    ->middleware('signed');

Route::get('description-images/{uuid}', [MediaController::class, 'descriptionImage'])
    ->whereUuid('uuid')
    ->name('description-image');

Route::get('media/{media}', [MediaController::class, 'show'])
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

Route::get('association/nostr-profile/{pubkey}', ProfileCard::class)
    ->where('pubkey', '[0-9a-f]{64}')
    ->middleware('throttle:nostr-profiles')
    ->name('nostr.profile-card');

Route::post('logout', LogoutController::class)->name('logout');

// Association Routes
Route::livewire('/association/profile', 'association.profile')->name('association.profile');
Route::livewire('/association/benefits', 'association.benefits')->name('association.benefits');

Route::livewire('/association/members/admin', 'association.members.admin')->name('association.members.admin');

Route::livewire('/association/election/admin', 'association.election.admin')->name('association.election.admin');
Route::livewire('/association/election/admin/{election}/screen', 'association.election.screen')->name('association.election.admin.screen');
Route::livewire('/association/election/admin/{election}/protocol', 'association.election.protocol')->name('association.election.admin.protocol');

Route::livewire('/association/news', 'association.news')->name('association.news');

Route::livewire('/association/election', 'association.election.index')->name('association.election');

Route::livewire('/association/messages', 'association.messages')->name('association.messages');

Route::livewire('/association/messages/admin', 'association.messages.admin')->name('association.messages.admin');

Route::livewire('/association/messages/inbox', 'association.messages.inbox')->name('association.messages.inbox');

Route::livewire('/association/project-support', 'association.project-support.index')->name('association.projectSupport');
Route::livewire('/association/project-support/create', 'association.project-support.form.create')->name('association.projectSupport.create');
Route::livewire('/association/project-support/{projectProposal:slug}', 'association.project-support.show')->name('association.projectSupport.item');
Route::livewire('/association/project-support/edit/{projectProposal:slug}', 'association.project-support.form.edit')->name('association.projectSupport.edit');

Route::livewire('/association/proposals', 'association.proposals.index')->name('association.proposals.index');
Route::livewire('/association/proposals/archive', 'association.proposals.index')->defaults('archive', true)->name('association.proposals.archive');
Route::livewire('/association/proposals/create', 'association.proposals.form')->name('association.proposals.create');
Route::livewire('/association/proposals/board', 'association.proposals.board')->name('association.proposals.board');
Route::livewire('/association/proposals/{memberProposal}', 'association.proposals.show')->whereNumber('memberProposal')->name('association.proposals.show');
Route::livewire('/association/proposals/{memberProposal}/edit', 'association.proposals.form')->whereNumber('memberProposal')->name('association.proposals.edit');
