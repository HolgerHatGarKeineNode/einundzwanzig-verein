<?php

use App\Http\Middleware\ThrottleApiV1;
use App\Http\Middleware\VerifyApiClient;
use App\Http\Middleware\VerifyNip98;
use App\Services\SecurityMonitor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            ThrottleRequests::class.':api',
        ]);

        /*
         * Vertrauenswuerdige Proxys — Standard: KEINE.
         *
         * Konfiguriert wird das in config/trustedproxy.php, NICHT hier per
         * trustProxies(at: env(...)): diese Closure laeuft, bevor Laravel die
         * .env laedt, ein hier gelesenes env('TRUSTED_PROXIES') ist also
         * immer leer. Am laufenden Server nachgemessen — mit
         * TRUSTED_PROXIES=127.0.0.1 in der .env blieb ein gefaelschter
         * X-Forwarded-For wirkungslos, der Schalter sah aus, als traege er.
         * Begruendung, Messung und der Weg fuer ein spaeteres CDN stehen
         * vollstaendig in jener Datei; TrustedProxiesTest prueft beide
         * Richtungen.
         */

        /*
         * Host-Header nur fuer die eigene Domain und den eigenen Rechner
         * akzeptieren.
         *
         * $request->fullUrl() bildet Schema und Host aus dem Host-Header, den
         * per Default nichts validiert. Verteidigung in der Tiefe hinter
         * Nip98::expectedUrl(), das den `u`-Vergleich ohnehin gegen
         * config('app.url') fuehrt — das Framework schaltet TrustHosts unter
         * APP_ENV=local und in Tests ab (TrustHosts::shouldSpecifyTrustedHosts),
         * es traegt die Absicherung also NICHT allein.
         *
         * Zwei Fallstricke, beide am laufenden Server unter APP_ENV=production
         * nachgemessen:
         *
         * 1. Symfony behandelt jeden Eintrag als REGEX und wendet ihn OHNE
         *    Anker an (Request::setTrustedHosts() umschliesst ihn mit
         *    '{%s}i'). Ein nackter Hostname passt damit als Teilstring:
         *    `verein.test.evil.example`, `xxverein.test` und
         *    `www.verein.test` kamen allesamt mit 200 durch — trotz
         *    subdomains: false. Deshalb preg_quote plus ^…$.
         * 2. Ohne `localhost`/`127.0.0.1` sperrt die Liste jeden Zugriff ueber
         *    den eigenen Rechner aus — auch den Health-Check `/up` aus
         *    withRouting() oben, der nach dem Deploy 400 statt 200
         *    geantwortet haette. Monitoring pingt selten den oeffentlichen
         *    DNS-Namen.
         *
         * Als Closure uebergeben, nicht als Array: der Wert wird erst zur
         * Anfragezeit ausgewertet, wenn die Konfiguration geladen ist.
         */
        $middleware->trustHosts(at: static function (): array {
            $hosts = ['localhost', '127.0.0.1', '[::1]'];
            $configured = parse_url((string) config('app.url'), PHP_URL_HOST);

            if (is_string($configured) && $configured !== '') {
                $hosts[] = $configured;
            }

            return array_map(
                static fn (string $host): string => '^'.preg_quote($host, '#').'$',
                $hosts
            );
        }, subdomains: false);

        $middleware->preventRequestForgery(except: [
            'webhooks/btcpay',
        ]);

        /*
         * Auth-Schicht der oeffentlichen API. Die Reihenfolge ist Absicht:
         *
         * 1. VerifyApiClient — WELCHE Anwendung ruft? Ein unbekannter Aufrufer
         *    fliegt raus, bevor eine Datenoperation oder eine Schnorr-Pruefung
         *    Arbeit kostet.
         * 2. VerifyNip98 — FUER WEN? Der signierte Pubkey ist die einzige
         *    Quelle des Subjekts.
         * 3. ThrottleApiV1 — Kontingent, gezaehlt pro Client-Name UND pro
         *    Pubkey. Steht am Ende, weil beide Schluessel erst hier
         *    feststehen; nicht authentifizierte Anfragen deckt die
         *    vorgeschaltete 'api'-Gruppe mit throttle:api ab.
         *
         * Warum ThrottleApiV1 und nicht throttle:api-v1: Laravel sortiert die
         * Kette vor der Ausfuehrung nach Kernel::$middlewarePriority und
         * zoege ein zweites ThrottleRequests an den Anfang — Begruendung und
         * Messung stehen in der Klasse. ApiV1RouteWiringTest prueft die
         * tatsaechliche Reihenfolge, damit ein Framework-Update das nicht
         * still zurueckdreht.
         */
        $middleware->group('api.v1', [
            VerifyApiClient::class,
            VerifyNip98::class,
            ThrottleApiV1::class.':api-v1',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Record Livewire tampering exceptions, then return false to stop them
        // reaching Sentry/Nightwatch/log. Must run before Integration::handles()
        // (callbacks fire in order; false short-circuits the rest). dontReport()
        // is unusable here — it short-circuits before the recording would run.
        $exceptions->report(function (Throwable $e): bool {
            $monitor = app(SecurityMonitor::class);

            if ($monitor->shouldRecord($e)) {
                $monitor->recordFromException($e);

                return false;
            }

            return true;
        });

        Integration::handles($exceptions);
    })->create();
