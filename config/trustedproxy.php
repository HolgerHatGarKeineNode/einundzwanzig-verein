<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vertrauenswuerdige Proxys
    |--------------------------------------------------------------------------
    |
    | Netze der Reverse Proxys / Load Balancer, denen X-Forwarded-*-Header
    | geglaubt wird. Standard ist die LEERE Liste: keinem.
    |
    | Warum diese Datei und nicht `trustProxies(at: ...)` in bootstrap/app.php:
    | die withMiddleware-Closure laeuft, BEVOR Laravel die .env laedt. Ein dort
    | gelesenes env('TRUSTED_PROXIES') ist immer leer — am laufenden Server
    | nachgemessen: mit TRUSTED_PROXIES=127.0.0.1 in der .env blieb ein
    | gefaelschter X-Forwarded-For weiterhin wirkungslos. Der Schalter sah aus,
    | als traege er, und tat es nicht. Config-Dateien werden dagegen NACH der
    | .env ausgewertet, und Illuminate\Http\Middleware\TrustProxies liest
    | `config('trustedproxy.proxies')` zur Laufzeit als dokumentierten
    | Rueckfallweg.
    |
    | Heute steht in Produktion kein Proxy davor (Forge-Server, nginx und
    | PHP-FPM auf demselben Host, kein CDN — A-Record 91.107.223.79, Hetzner).
    | nginx reicht per fastcgi_params den echten REMOTE_ADDR durch;
    | $request->ip() ist dort bereits die Client-IP und nicht faelschbar.
    | Stuende hier faelschlich '*', duerfte JEDER Aufrufer seine IP frei
    | waehlen — Drosselung und Angriffsprotokoll zeigten ins Leere.
    |
    | Die leere Liste ist ausdrueckliches "trust none", kein Zufall: eine
    | leere Liste laesst TrustProxies setTrustedProxies([]) aufrufen, waehrend
    | null die eingebaute Sonderbehandlung fuer *.on-forge.com und Laravel
    | Cloud erreichen wuerde, die von sich aus auf '*' schaltet.
    |
    | Kommt ein CDN oder Load Balancer davor, traegt der Betreiber dessen
    | Netze in TRUSTED_PROXIES ein (kommagetrennt, CIDR erlaubt); "*" heisst
    | "jedem vorgelagerten Hop glauben".
    |
    */

    'proxies' => (static function (): array|string {
        $raw = trim((string) env('TRUSTED_PROXIES', ''));

        if ($raw === '*' || $raw === '**') {
            return $raw;
        }

        return array_values(array_filter(array_map(
            'trim',
            $raw === '' ? [] : explode(',', $raw)
        )));
    })(),

];
