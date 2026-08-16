<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;

/*
 * Die Header aus `App\Http\Middleware\SecurityHeaders`, geprüft an echten
 * Antworten statt an der Middleware — sie hängt in `bootstrap/app.php`, und
 * ein Test der Klasse allein bliebe grün, wenn genau diese Zeile verschwindet.
 */

it('setzt die Sicherheits-Header auf einer öffentlichen Seite', function () {
    $response = $this->get(route('association.projectSupport'));

    $response->assertOk();

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'self'")
        ->toContain("form-action 'self'")
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()');
});

it('setzt sie auch auf der API, nicht nur im web-Zweig', function () {
    // Angehängt an den globalen Stack und nicht an eine Gruppe — eine
    // Verschiebung in `->web(...)` würde diesen Test rot machen.
    $this->getJson('/api/members/2024')
        ->assertSuccessful()
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('verspricht in der CSP nichts über script-src', function () {
    /*
     * DIE WICHTIGSTE ZUSICHERUNG DIESER DATEI, und sie ist eine negative.
     *
     * Ein `script-src` wäre hier nur mit `unsafe-inline` und `unsafe-eval` zu
     * haben — Livewire liefert Alpine mit `eval()` aus (`csp_safe => false`)
     * und die Anwendung hat Inline-Scripts ohne Nonce. Ein solcher Wert
     * erlaubt exakt das, was ein Angreifer einschleust: er sieht im Audit nach
     * Schutz aus und ist keiner.
     *
     * Wer die Direktive später ergänzt, muss vorher `csp_safe` umstellen und
     * Nonces einführen. Bis dahin fällt dieser Test, statt dass die Lücke als
     * geschlossen gilt.
     */
    $csp = (string) $this->get(route('association.projectSupport'))->headers->get('Content-Security-Policy');

    expect(str_contains($csp, 'unsafe-inline'))->toBeFalse('Ein script-src mit unsafe-inline schützt gegen eingeschleustes JS nicht.')
        ->and(str_contains($csp, 'unsafe-eval'))->toBeFalse('Ein script-src mit unsafe-eval schützt gegen eingeschleustes JS nicht.');
});

it('überschreibt keinen Header, den eine Antwort bereits mitbringt', function () {
    /*
     * Der Server setzt vorne eigene Sicherheits-Header. Verschärft dort
     * jemand die Vorgabe, darf die Anwendung sie nicht still durch ihre
     * eigene, schwächere ersetzen.
     *
     * Gemessen an einer Route, die den Header SELBST setzt und dann durch die
     * Middleware läuft — den Header nachträglich am fertigen Response-Objekt
     * zu setzen und ihn dort wiederzufinden, würde nur beweisen, dass ein
     * Setter setzt.
     */
    Route::middleware([SecurityHeaders::class])->get('/_test/preset-csp', fn () => response('ok')
        ->header('Content-Security-Policy', "default-src 'none'"));

    $response = $this->get('/_test/preset-csp');

    $response->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'")
        // Die übrigen Header setzt sie weiterhin — nur der belegte bleibt, wie
        // er war.
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin');
});
