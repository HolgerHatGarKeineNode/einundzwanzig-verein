{{--
    Die Scalar-Referenz fuer /api/v1 — bewusst eine eigene View statt
    `scramble::scalar`.

    Die mitgelieferte View laedt das Bundle mit einem klassischen
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"> und ruft
    `Scalar.createApiReference()` direkt danach in einem Inline-Script auf. Das
    ist genau die eine Konstruktion, die sich hier NICHT halten laesst: Sobald
    das Bundle aus dem eigenen Origin kommt, laeuft es ueber @vite und damit als
    Modul — Module sind deferred, das Inline-Script waere lange vorher gelaufen
    und `Scalar` noch undefined. Deshalb ist der Aufruf hier ebenfalls ein
    Modul: Modul-Scripts ohne `async` laufen in Dokumentreihenfolge, der Aufruf
    also nach dem Bundle. Warum ueberhaupt aus dem eigenen Origin, steht in
    resources/js/scalar.js.

    Die Seite ist oeffentlich (config/scramble.php, `middleware`) und enthaelt
    kein Geheimnis: das eingebettete Dokument ist dasselbe, das unter
    /docs/v1/api.json ausgeliefert wird.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ $config->get('ui.title') ?? config('app.name').' - API Docs' }}</title>
</head>
<body>
<div id="app"></div>

@vite('resources/js/scalar.js')

<script type="module">
    window.Scalar.createApiReference('#app', {
        content: @json($spec),

        {{--
            Die Renderer-Konfiguration UNGEFILTERT. Hier stand einmal ein
            `reject(fn ($value) => $value === null)`, und das war ein Fehler mit
            Ansage: Es warf `'proxyUrl' => null` weg, statt es durchzureichen —
            und was dann passierte, entschied nicht mehr diese Anwendung,
            sondern welches Schema auf Scalar-Seite fuer den fehlenden
            Schluessel zustaendig war (einmal `optional()`, wenige Zeilen
            weiter `prefault('https://proxy.scalar.com')`). Der Wert ist jetzt
            ein leerer String und schaltet den Proxy positiv ab
            (`shouldUseProxy()`: `if (!proxyUrl || !url) return false`).
            Begruendung im Langen in config/scramble.php.

            `cdn` und `credentials` bleiben ausgenommen: das eine benutzt diese
            View nicht, das andere geht unten in customFetch ein.
        --}}
        ...@json($config->renderer()->all(except: ['cdn', 'credentials'])),

        {{--
            `credentials: 'omit'`. /api/v1 ist zustandslos, haengt nicht in der
            web-Gruppe und authentifiziert ausschliesslich ueber Header. Das
            Session-Cookie dieses Lesers dorthin mitzuschicken wuerde also eine
            Berechtigung mitreisen lassen, die die API weder braucht noch
            auswertet. Aus demselben Grund fehlt der CSRF-Header der
            Original-View: /api/v1 kennt keinen CSRF-Schutz, den er befriedigen
            koennte.
        --}}
        customFetch: (input, init) => window.fetch(input, {
            ...init,
            credentials: @json($config->renderer()->get('credentials', 'omit')),
        }),
    })
</script>
</body>
</html>
