<?php

/*
|--------------------------------------------------------------------------
| Erlaubte Origins
|--------------------------------------------------------------------------
|
| Reverb vergleicht den HOST des `Origin`-Headers gegen diese Liste
| (`Laravel\Reverb\Protocols\Pusher\Server::verifyOrigin()` — `parse_url(..., PHP_URL_HOST)`
| plus `Str::is()`, Platzhalter wie `*.example.com` sind also erlaubt). Eintraege sind
| Hostnamen, keine URLs, und `*` haette den Vergleich komplett abgeschaltet.
|
| Ohne `REVERB_ALLOWED_ORIGINS` gilt allein der Host aus `APP_URL` — die eigene Seite
| ist der einzige Client, den dieses Repo kennt (`resources/js/echo.js`). Weitere
| Origins werden kommagetrennt ergaenzt, nicht ersetzt: der APP_URL-Host bleibt immer
| in der Liste, damit ein unvollstaendig gesetztes Env die eigene Seite nicht aussperrt.
|
*/

$reverbAppHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';

$reverbAllowedOrigins = array_values(array_unique(array_filter(
    array_merge(
        [$reverbAppHost],
        array_map('trim', explode(',', (string) env('REVERB_ALLOWED_ORIGINS', ''))),
    ),
    static fn (string $origin): bool => $origin !== '',
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        // Genau EINE App, und ihre Zugangsdaten stehen im Env — nicht hier. Bis
        // 2026-08-06 lagen `key` und `secret` als Literale in diesem oeffentlichen
        // Repo; die Namen decken sich mit `config/broadcasting.php:35-37`, damit
        // Broadcaster und Server dieselbe App meinen.
        //
        // Die zweite, hier frueher definierte App (`key = 'test'`, `app_id = 521001`)
        // ist entfallen: kein Aufrufer im Repo kannte sie, und sie las bereits
        // `REVERB_APP_SECRET` — nach der Umstellung haette sie sich damit dasselbe
        // Geheimnis wie die echte App geteilt und die Origin-Beschraenkung unter
        // einem oeffentlich bekannten Key umgehbar gemacht.
        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => $reverbAllowedOrigins,
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
            ],
        ],

    ],

];
