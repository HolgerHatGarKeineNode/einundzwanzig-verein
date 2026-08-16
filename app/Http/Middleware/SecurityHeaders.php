<?php

namespace App\Http\Middleware;

use App\Support\RichTextSanitizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Antwort-Header, die der Browser als zweite Schicht hinter der
 * Anwendung durchsetzt.
 *
 * ANLASS war ein gespeichertes XSS in einer Projektbeschreibung: Der Ausweg
 * daraus ist der Sanitizer ({@see RichTextSanitizer}), und der
 * schliesst die Luecke auch. Diese Header sind das, was uebrig bleibt, wenn
 * er eines Tages eine Luecke hat — sie machen aus einer Ausfuehrung eine
 * folgenlose.
 *
 * WAS HIER BEWUSST FEHLT, UND DAS IST DIE WICHTIGSTE ZEILE DIESER DATEI:
 * `script-src`. Genau die Direktive wuerde eingeschleustes Inline-JS stoppen —
 * und genau sie ist hier nicht ohne Umbau zu haben. Livewire liefert Alpine in
 * einer Variante aus, die `eval()` benutzt (`config/livewire.php`,
 * `csp_safe => false`), und die Anwendung enthaelt Inline-Scripts ohne Nonce.
 * Ein `script-src 'self' 'unsafe-inline' 'unsafe-eval'` waere gegen die
 * Angriffsklasse, um die es geht, exakt wirkungslos — es erlaubt ja gerade
 * das, was der Angreifer einschleust. Ein solcher Header waere schlimmer als
 * keiner: er sieht im Audit nach Schutz aus und ist keiner.
 *
 * Der Weg dorthin ist bekannt und ist ein eigenes Vorhaben: `csp_safe => true`
 * schalten, jedes Inline-Script auf einen Nonce umstellen, jeden
 * Alpine-Ausdruck gegen die CSP-sichere Variante testen. Das beruehrt jede
 * Seite der Anwendung und gehoert nicht in einen Sicherheits-Hotfix.
 *
 * Was unten steht, ist deshalb bewusst die Menge, die WIRKT UND NICHTS
 * BRICHT — jede Direktive einzeln geprueft:
 *
 *  - `object-src 'none'` — kein <object>/<embed>. Die Anwendung nutzt beides
 *    nicht; ein Angreifer, der HTML unterbringt, nutzt es sonst gern.
 *  - `base-uri 'self'` — ein eingeschleustes <base href="..."> kann sonst
 *    JEDEN relativen Script- und Formularpfad der Seite auf einen fremden
 *    Host umbiegen, ohne selbst ein Script zu sein.
 *  - `frame-ancestors 'self'` — Clickjacking. Loest `X-Frame-Options:
 *    SAMEORIGIN` ab, das nginx bereits setzt; beide zusammen sind kein
 *    Widerspruch, moderne Browser lesen diese hier.
 *  - `form-action 'self'` — ein eingeschleustes Formular kann seine Eingaben
 *    nicht nach draussen posten. Geprueft: die Anwendung hat genau EIN
 *    <form action>, und das zeigt auf die eigene Logout-Route.
 *
 * `Referrer-Policy` haelt Pfade eigener Seiten aus fremden Logs heraus —
 * Antrags-Slugs sind sprechend. `Permissions-Policy` schaltet Geraete ab, die
 * diese Seite nie braucht; auch das wirkt gegen fremdes HTML, das sie
 * anfordert.
 */
class SecurityHeaders
{
    /**
     * @var array<string, string>
     */
    private const HEADERS = [
        'Content-Security-Policy' => "object-src 'none'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'",
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::HEADERS as $header => $value) {
            /*
             * Nie ueberschreiben, was vorne schon gesetzt wurde. Der Proxy
             * setzt bereits eigene Sicherheits-Header, und eine Anwendung, die
             * eine strengere Vorgabe von dort still durch ihre eigene ersetzt,
             * schwaecht sie in dem Moment, in dem jemand sie verschaerft.
             */
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        return $response;
    }
}
