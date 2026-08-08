<?php

namespace Database\Seeders;

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Ein Mitglied mit BEKANNTEM Schlüssel, damit sich der Anmelde- und
 * Abstimmungsweg von Hand durchspielen lässt — genau die Rolle aus dem
 * Bugreport zur Abstimmungskarte: einfaches Mitglied, kein Vorstand.
 *
 * Aufruf ausdrücklich und einzeln:
 *
 *     php artisan db:seed --class=LocalTestMemberSeeder
 *
 * nsec1atst96xft6tkp0ey6y0r9x3yznxqlgtxs4uhdle76043zsh8me2sztn8e2
 * npub1eafp7zhujw0c6xwsgaycuvf2ysj0k7u5nvupndlv7398d9v4tmyq8unh4h
 *
 * ZWEI unabhängige Schlösser, und beide werden gebraucht:
 *
 * 1. Diese Klasse hängt bewusst NICHT in der `$this->call([...])`-Kette von
 *    `DatabaseSeeder`. Ein `php artisan db:seed` oder `migrate:fresh --seed`
 *    erreicht sie deshalb nie; wer sie will, nennt sie beim Namen.
 * 2. Die Umgebungsprüfung unten wirft, statt still zu überspringen.
 *
 * Warum die Umgebungsprüfung allein NICHT reicht — der Grund, aus dem dieser
 * Seeder überhaupt eine eigene Datei ist: `.env.example:2` liefert
 * `APP_ENV=local` als Vorlage aus. Ein Server, der beim Aufsetzen per
 * `cp .env.example .env` startet und das Überschreiben vergisst, erfüllt die
 * Bedingung „local" wortwörtlich. Stünde der Block dann in einer Klasse, die
 * `db:seed` ohnehin mitzieht, entstünde dort ein stimmberechtigtes
 * Mitgliedskonto, dessen privater Schlüssel oben im Klartext steht und damit
 * jedem Leser dieses Repos gehört. Der explizite Aufruf ist die Barriere, die
 * ein falsch gesetztes `APP_ENV` nicht aushebeln kann.
 *
 * Der Status ist PASSIVE und es gibt bewusst KEIN PaymentEvent: Die Abstimmung
 * hängt an der Existenz eines Mitgliedseintrags, nicht am Beitrag
 * (`VotePolicy::create()`, `isVoter` in `project-support/show.blade.php`). Wer
 * hier ein PaymentEvent ergänzt, prüft eine Bedingung mit, die es für die
 * Abstimmung nicht gibt — und merkt nie, wenn sie später doch eingezogen wird.
 */
class LocalTestMemberSeeder extends Seeder
{
    private const NPUB = 'npub1eafp7zhujw0c6xwsgaycuvf2ysj0k7u5nvupndlv7398d9v4tmyq8unh4h';

    private const PUBKEY = 'cf521f0afc939f8d19d047498e312a2424fb7b949b3819b7ecf44a7695955ec8';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'LocalTestMemberSeeder legt ein Mitglied mit öffentlich bekanntem Schlüssel an '
                .'und läuft ausschließlich in der Umgebung "local". Aktuell: "'.app()->environment().'".'
            );
        }

        $pleb = EinundzwanzigPleb::query()->firstOrCreate(
            ['pubkey' => self::PUBKEY],
            [
                'npub' => self::NPUB,
                'email' => 'passiv@localhost.test',
                'nip05_handle' => 'passivtest',
                'application_text' => 'Lokaler Testzugang: einfaches Mitglied ohne Vorstandsrechte.',
            ]
        );

        $pleb->association_status = AssociationStatus::PASSIVE;
        $pleb->save();

        $this->command?->info('Lokales Testmitglied bereit (PASSIVE, ohne Beitrag): '.self::NPUB);
    }
}
