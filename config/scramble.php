<?php

return [

    /*
     * Only /api/v1 is documented, and the pattern carries TWO decisions.
     *
     * The first is what is included: the two unversioned endpoints
     * `GET /api/members/{year}` and `GET /api/nostr/profile/{key}` predate this
     * surface, are not part of it, and must not appear in a consumer-facing
     * document. A prefix of `api` would have pulled both in.
     *
     * The second is the trailing `*`, and it is not cosmetic. Scramble strips a
     * single static prefix from every path and moves it into the server URL
     * (`ApiPath::usesSingleBase()`), so `api/v1` would have produced paths like
     * `/membership/me` with the version living only in the server entry. A
     * wildcard keeps the full `/api/v1/membership/me` in the document, which is
     * the string a client actually requests, the string the routes carry, and
     * the string a contract test can compare against `Route::getRoutes()`
     * without reassembling it from two places.
     */
    'api_path' => ['include' => 'api/v1/*'],

    'api_domain' => null,

    /*
     * The export is a reviewable artifact under version control, written by
     * `php artisan scramble:export --path=docs/openapi/v1.json --api=v1`. This
     * default only applies when that flag is omitted.
     */
    'export_path' => 'docs/openapi/v1.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        /*
         * The version of the API SURFACE, not of the deployment. Hardcoded
         * rather than read from the environment because the exported document
         * is committed: an env-dependent value would change with whoever ran
         * the export last.
         */
        'version' => '1.0.0',

        /*
         * Filled in by MembershipApiDocumentTransformer. It is prose with a
         * warning a consumer has to read, and prose belongs next to the rest
         * of the document text rather than in a config array.
         */
        'description' => '',
    ],

    'ui' => [
        'title' => 'EINUNDZWANZIG Membership API',
    ],

    'renderer' => 'scalar',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],

        'scalar' => [
            /*
             * Our own view instead of `scramble::scalar`. The shipped one loads
             * the reference from jsDelivr with a plain <script src>; ours loads
             * it through Vite with @vite, which is the only construction that
             * works both against the dev server and against a built manifest.
             * See resources/views/docs/scalar.blade.php for why the bootstrap
             * had to move into a module along with it.
             */
            'view' => 'docs.scalar',

            /*
             * Unused by our view, and null so that nothing can quietly fall
             * back to the package default `https://cdn.jsdelivr.net/npm/@scalar/api-reference`.
             * The asset is served from this origin — see the view.
             */
            'cdn' => null,

            'theme' => 'laravel',

            'darkMode' => true,

            /*
             * NO WEBFONTS FROM SCALAR. Left at its default, the reference
             * injects @font-face rules pointing at `https://fonts.scalar.com`
             * at runtime — measured against the built page with a headless
             * browser: three woff2 requests to that host, and the HTML says
             * nothing about them, because the rules are written by JavaScript
             * after the page has loaded. Self-hosting the bundle and then
             * handing every reader's IP to the same vendor through the font
             * files would have missed the point. The theme falls back to the
             * system font stack.
             */
            'withDefaultFonts' => false,

            /*
             * NO PROXY, and an EMPTY STRING is what says so.
             *
             * The value is handed to Scalar and decided by `shouldUseProxy()`
             * in @scalar/helpers, whose first line is
             * `if (!proxyUrl || !url) return false` — an empty string is falsy,
             * so this positively switches the proxy off.
             *
             * `null` was the wrong instrument and was measured to be: the view
             * dropped null keys, so the key never reached Scalar at all, and
             * the outcome then rested on which of the vendor's own schemas
             * applied — `proxyUrl: z.string().optional()` in the API-reference
             * config, but `z.string().prefault('https://proxy.scalar.com')` in
             * `externalUrlsSchema` a few lines away. Not our decision to leave
             * to a vendor default.
             *
             * What is at stake: every "Test Request" a reader fires would
             * travel through that third party — client key and signed NIP-98
             * credential included. There is nothing to proxy either way, since
             * the documented API is same-origin with this page.
             */
            'proxyUrl' => '',

            'showDeveloperTools' => 'never',

            'agent' => ['disabled' => true],

            /*
             * `omit`, not the package default `include`. /api/v1 is stateless,
             * is not in the `web` middleware group and authenticates purely
             * through headers, so a reader's session cookie riding along on a
             * "Test Request" would be an ambient credential the API neither
             * needs nor honours.
             */
            'credentials' => 'omit',
        ],
    ],

    /*
     * Production first, so a reader's "Test Request" defaults to the real API
     * rather than to a host only the developer has.
     *
     * The production URL is written out rather than derived from
     * `config('app.url')` because the export is committed: deriving it would
     * bake in whatever APP_URL the exporting machine happened to have
     * (`http://localhost:8000` on a dev box). The host is the one
     * `bootstrap/app.php` trusts in production and the one TrustedHostsTest
     * pins.
     *
     * The local entry matches the port `php artisan serve` uses, and that
     * matters beyond convenience: a NIP-98 event is signed for an absolute URL
     * built from APP_URL (`Nip98::expectedUrl()`), so a server entry that
     * disagrees with APP_URL produces credentials the API refuses.
     */
    'servers' => [
        'Production' => 'https://verein.einundzwanzig.space',
        'Local' => 'http://localhost:8000',
    ],

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    /*
     * PUBLIC, DELIBERATELY — the package default is RestrictedDocsAccess,
     * which serves the page in `local` only.
     *
     * This is consumer documentation for third-party clients: the people who
     * need it are by definition outside this deployment, and a gate would mean
     * handing the file around by mail instead. It discloses nothing that is not
     * already public — the endpoints, their shapes and both authentication
     * schemes are what a client implements against, and neither the client keys
     * nor any member data are part of the document. Access control on
     * /api/v1 itself is unaffected and unchanged.
     *
     * PUBLIC IS NOT UNGUARDED, though. Scramble rebuilds the whole document on
     * every request — `CacheableGenerator` reads a cache it never writes, and
     * only `php artisan scramble:cache` fills it — so an unlimited public route
     * here is roughly twenty times the CPU of an ordinary one, for free, to
     * anybody. The `docs` limiter bounds that; its number and its reasoning are
     * in AppServiceProvider.
     */
    'middleware' => [
        'web',
        'throttle:docs',
    ],

    'extensions' => [],

    /*
     * Security is described explicitly per operation in
     * MembershipApiDocumentTransformer. MiddlewareAuthSecurityStrategy keys off
     * `auth`/`auth:*` middleware and would document bearer auth; this surface
     * has neither — it uses a client key header plus a NIP-98 signature, and
     * `GET /membership/config` requires only the first of the two.
     */
    'security_strategy' => null,
];
