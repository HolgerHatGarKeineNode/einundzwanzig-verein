<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EinundzwanzigPleb;
use Illuminate\Http\Request;

/**
 * GET /api/members/{year} — Liste aller Mitglieder mit bezahltem Beitrag fuer ein Jahr.
 *
 * BEWUSST UNAUTHENTIFIZIERT (entschieden 2026-08-06). Das ist kein Versehen und kein
 * offener Befund: Der Endpunkt hat externe Konsumenten, jede Aenderung an Zugang,
 * Feldliste oder Route waere ein Breaking Change. Er bleibt deshalb unversioniert und
 * offen; die neue `/api/v1`-Flaeche fuehrt dieses Muster NICHT fort.
 *
 * Offengelegt wird damit nicht der npub — der ist auf Nostr ohnehin oeffentlich —,
 * sondern die VERKNUEPFUNG "dieser Pubkey ist Mitglied im Einundzwanzig e.V. und hat
 * fuer Jahr X bezahlt". Wer den Endpunkt aufruft, kann fuer jeden beliebigen Pubkey
 * genau das nachschlagen. Die Frage nach Rechtsgrundlage bzw. Opt-in pro Mitglied ist
 * ausdruecklich noch offen (Plan `docs/plans/2026-08-06T1114-mitgliedschafts-api.md`,
 * Offene Frage 6) — die Entscheidung, den Endpunkt offen zu lassen, ist davon unberuehrt.
 *
 * DIE `select()`-ZEILE IST DER EINZIGE SCHUTZ DER PERSONENDATEN und darf nicht
 * erweitert werden. `EinundzwanzigPleb` hat kein `$hidden`; CipherSweet verschluesselt
 * `email` nur at rest und entschluesselt bei der Serialisierung. Ein zusaetzliches Feld
 * in der Liste — oder ein `select()`, das entfaellt — veroeffentlicht die Klartext-
 * E-Mail-Adressen aller zahlenden Mitglieder in derselben oeffentlichen Antwort.
 * Dasselbe gilt fuer `application_text` und `archived_application_text`.
 *
 * Der Regressionsschutz dazu steht in `tests/Feature/GetPaidMembersTest.php` (Allowlist
 * nach dem Muster `toHaveKeys()->not->toHaveKeys()`).
 */
class GetPaidMembers extends Controller
{
    public function __invoke($year, Request $request)
    {
        $paidMembers = EinundzwanzigPleb::query()
            ->whereHas('paymentEvents', function ($query) use ($year) {
                $query->where('year', $year)
                    ->where('paid', true);
            })
            ->select('id', 'npub', 'pubkey', 'nip05_handle')
            ->get();

        return response()->json($paidMembers);
    }
}
