<?php

use App\Http\Controllers\Api\GetPaidMembers;
use App\Http\Controllers\Api\Nostr\GetProfile;
use App\Http\Controllers\Api\V1\Membership\DeleteMembershipController;
use App\Http\Controllers\Api\V1\Membership\ExportDataController;
use App\Http\Controllers\Api\V1\Membership\ListPaymentsController;
use App\Http\Controllers\Api\V1\Membership\RefreshPaymentController;
use App\Http\Controllers\Api\V1\Membership\ShowConfigController;
use App\Http\Controllers\Api\V1\Membership\ShowMembershipController;
use App\Http\Controllers\Api\V1\Membership\StoreAppApplicationController;
use App\Http\Controllers\Api\V1\Membership\StoreAppInvoiceController;
use App\Http\Controllers\Api\V1\Membership\StoreApplicationController;
use App\Http\Controllers\Api\V1\Membership\StoreInvoiceController;
use App\Http\Middleware\ThrottleApiV1;
use App\Http\Middleware\VerifyApiClient;
use Illuminate\Support\Facades\Route;

// NIP-01 kennt genau ein Pubkey-Format: 64 Zeichen Kleinbuchstaben-Hex. Alles andere
// ist kein Pubkey, sondern ein Tippfehler oder ein Scan — und wird hier abgewiesen,
// bevor der Controller laeuft. Gleiches Muster wie `Nostr\GetProfiles`
// (`app/Http/Controllers/Nostr/GetProfiles.php:60`).
Route::get('/nostr/profile/{key}', GetProfile::class)
    ->where('key', '[0-9a-f]{64}');

Route::get('/members/{year}', GetPaidMembers::class);

/*
 * Die versionierte Mitgliedschafts-API.
 */
Route::prefix('v1/membership')->name('api.v1.membership.')->group(function () {
    /*
     * Ohne NIP-98, mit Client-Key: siehe ShowConfigController. Die Middleware
     * steht hier einzeln statt als Gruppe `api.v1`, weil genau EIN Glied der
     * Gruppe fehlen soll — der Ausweis des Endnutzers. Client-Pruefung und
     * Kontingent bleiben.
     *
     * Der `api-v1`-Limiter traegt das: sein Pubkey-Eimer faellt auf die IP
     * zurueck, wenn kein Pubkey feststeht (AppServiceProvider), der Endpunkt
     * ist also nicht ungedrosselt, nur anders geschluesselt.
     */
    Route::get('/config', ShowConfigController::class)
        ->middleware([VerifyApiClient::class, ThrottleApiV1::class.':api-v1'])
        ->name('config');

    Route::middleware('api.v1')->group(function () {
        Route::get('/me', ShowMembershipController::class)->name('me');

        /*
         * Loeschung nach revDSG: anonymisiert den Personenbezug, der
         * Buchungssatz bleibt. Begruendung Feld fuer Feld in
         * MembershipService::erasePersonalData().
         */
        Route::delete('/me', DeleteMembershipController::class)->name('me.delete');

        Route::get('/payments', ListPaymentsController::class)->name('payments');
        Route::get('/export', ExportDataController::class)->name('export');

        Route::post('/applications', StoreApplicationController::class)->name('applications.store');

        /*
         * Das Invoice-Kontingent haengt NUR hier: jeder Aufruf ist ein POST an
         * BTCPay mit dem API-Key des Vereins, also fremde Arbeit auf unsere
         * Rechnung. `refresh` traegt es bewusst nicht — es ist ein lesender
         * GET nach draussen, erzeugt nichts, und wuerde sonst das Kontingent
         * aufbrauchen, das der Beitritt selbst braucht. Fuer refresh gilt das
         * allgemeine api-v1-Kontingent aus der Gruppe.
         *
         * `{year}` ist auf vier Ziffern beschraenkt, damit Muell die Route gar
         * nicht erst erreicht; welches Jahr zulaessig ist, entscheidet der
         * Controller (nur das laufende — Begruendung dort).
         */
        Route::post('/payments/{year}/invoice', StoreInvoiceController::class)
            ->where('year', '[0-9]{4}')
            ->middleware(ThrottleApiV1::class.':api-v1-invoice')
            ->name('payments.invoice');

        Route::post('/payments/{year}/refresh', RefreshPaymentController::class)
            ->where('year', '[0-9]{4}')
            ->name('payments.refresh');
    });
});

/*
 * Der App-Zweig: dieselbe Mitgliedschafts-API fuer die NATIVE App, ohne
 * NIP-98 (Entscheid des Auftraggebers: die App nennt ihren npub, die Zahlung
 * ist die Beglaubigung — Begruendung und Grenzen in
 * StoreAppApplicationRequest). DREI Endpunkte, absichtlich nicht mehr:
 *
 *   GET  /config            — Beitrag und Statuten, wie im Web-Zweig
 *   POST /applications      — Antrag + Statuten-Zustimmung, Subjekt im Body
 *   POST /payments/{y}/invoice — BTCPay-Checkout, Subjekt im Body
 *
 * ES GIBT KEIN /me, KEIN /payments (Liste) und KEIN /export hier: ohne
 * Signatur waeren das Orakel fuer die Mitgliedsdaten FREMDER Pubkeys. Und
 * `refresh` fehlt ebenso — der App-Client hat keinen Weg, eine Zahlung beim
 * Verein nachziehen zu muessen: Webhook und Cron entscheiden serverseitig,
 * der Client sieht die Freischaltung ueber die relay-signierte Liste. Wer
 * heute einen vierten Endpunkt hier anfuegt, erweitert die Antwort auf die
 * Frage „was darf ein Client-Key ohne Signatur?“ — und die lautet: Antrag
 * stellen und eine Rechnung ziehen, mehr nicht.
 */
Route::prefix('v1/app/membership')->name('api.v1.app.membership.')->group(function () {
    Route::get('/config', ShowConfigController::class)
        ->middleware([VerifyApiClient::class, ThrottleApiV1::class.':api-v1'])
        ->name('config');

    Route::middleware('api.v1.app')->group(function () {
        Route::post('/applications', StoreAppApplicationController::class)->name('applications.store');

        Route::post('/payments/{year}/invoice', StoreAppInvoiceController::class)
            ->where('year', '[0-9]{4}')
            ->middleware(ThrottleApiV1::class.':api-v1-invoice')
            ->name('payments.invoice');
    });
});
