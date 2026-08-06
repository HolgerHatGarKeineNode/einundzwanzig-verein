<?php

namespace App\Providers;

use App\Models\Election;
use App\Models\ProjectProposal;
use App\Models\Vote;
use App\Policies\ElectionPolicy;
use App\Policies\ProjectProposalPolicy;
use App\Policies\VotePolicy;
use App\Support\ApiIdentity;
use App\Support\NostrAuth;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ProjectProposal::class, ProjectProposalPolicy::class);
        Gate::policy(Vote::class, VotePolicy::class);
        Gate::policy(Election::class, ElectionPolicy::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('voting', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Profil-Seed (GET /nostr/profiles): jede Anfrage kann bis zu 100 pubkeys
        // tragen und bei Cache-Miss eine WS-Verbindung zum Indexer auslösen. 30/min
        // deckt den realen Bedarf klar ab — ein Raumwechsel kostet meist einen
        // Aufruf, ein sehr großer Raum wenige — begrenzt aber die Relay-Arbeit, die
        // ein einzelnes Konto anstoßen kann. Schlüssel ist der angemeldete pubkey
        // (der Endpunkt ist ohnehin nur angemeldet erreichbar), damit ein Nutzer
        // hinter geteilter IP nicht alle anderen mit ausbremst.
        RateLimiter::for('nostr-profiles', function (Request $request) {
            return Limit::perMinute(30)->by(NostrAuth::pubkey() ?? $request->ip());
        });

        RateLimiter::for('nostr-login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        /*
         * /api/v1: zwei Eimer nebeneinander, weil zwei verschiedene Dinge
         * begrenzt werden. Der Client-Eimer deckelt, was eine Anwendung
         * INSGESAMT durchreichen darf — ein fehlgeleiteter Fremd-Client legt
         * damit nicht die API lahm. Der Pubkey-Eimer deckelt, was ein
         * EINZELNER Endnutzer anrichten kann, auch quer ueber mehrere Clients
         * hinweg. Beide muessen passieren; der engere greift zuerst.
         *
         * Bewusst NICHT nach IP: die Limiter zaehlten bisher $request->ip(),
         * waehrend SecurityMonitor X-Forwarded-For las — hinter einem Proxy
         * teilten sich damit alle Clients einen Eimer, und ohne Proxy war der
         * Header frei waehlbar. Client-Name und Pubkey sind kryptografisch
         * bzw. per Konfiguration festgenagelt und damit die belastbareren
         * Schluessel. (Der Widerspruch selbst ist mit trustProxies() in
         * bootstrap/app.php aufgeloest.)
         */
        RateLimiter::for('api-v1', function (Request $request) {
            $limits = (array) config('einundzwanzig.config.api_rate_limits', []);

            return [
                Limit::perMinute((int) ($limits['client_per_minute'] ?? 120))
                    ->by('api-v1:client:'.(ApiIdentity::client($request) ?? 'unresolved')),
                Limit::perMinute((int) ($limits['pubkey_per_minute'] ?? 30))
                    ->by('api-v1:pubkey:'.(ApiIdentity::pubkeyOrNull($request) ?? $request->ip())),
            ];
        });

        /*
         * Invoice-Erzeugung: eng, weil jeder Aufruf ein POST an BTCPay mit dem
         * API-Key des VEREINS ist — fremde Arbeit auf unsere Rechnung, und
         * jede erzeugte Rechnung bleibt als offener Posten stehen. Drei pro
         * Pubkey und Tag decken Erst-, Wiederhol- und Korrekturversuch ab.
         * Als eigener Limiter registriert, damit P4 ihn genau an den
         * Invoice-Endpunkt haengen kann und nicht an die ganze Flaeche.
         */
        RateLimiter::for('api-v1-invoice', function (Request $request) {
            $limits = (array) config('einundzwanzig.config.api_rate_limits', []);

            return Limit::perDay((int) ($limits['invoice_per_day'] ?? 3))
                ->by('api-v1-invoice:pubkey:'.(ApiIdentity::pubkeyOrNull($request) ?? $request->ip()));
        });
    }
}
