import {defineConfig} from 'vite';
import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import {existsSync} from 'node:fs';
import {resolve} from 'node:path';

/**
 * Liegt das Package-Repo als Nachbarverzeichnis daneben, arbeiten wir direkt
 * dagegen — sonst gegen die in package.json gepinnte GitHub-Version.
 *
 * Damit braucht eine Aenderung am Package lokal KEINEN Zyklus aus commit, push
 * und npm update: Vite folgt dem Pfad und laedt das Roh-TS live (inkl. HMR).
 * Auf dem Server existiert das Nachbarverzeichnis nicht, dort greift
 * automatisch der Pin. Dieselbe Mechanik wie beim Composer-path-Repo, das
 * ebenfalls uebersprungen wird, wenn der Pfad fehlt.
 *
 * Die Abhaengigkeiten des Packages (@welshman/* usw.) loest Vite dabei vom
 * realen Pfad aus auf und findet sie in den node_modules des Nachbar-Repos.
 */
const localPackage = resolve(import.meta.dirname, '../einundzwanzig-group/packages/einundzwanzig-group');
const useLocalPackage = process.env.GROUP_PACKAGE_LOCAL !== '0'
    && existsSync(resolve(localPackage, 'package.json'));

if (useLocalPackage) {
    console.log('\x1b[33m→ @einundzwanzig/group: lokales Nachbar-Repo (nicht der Pin aus package.json)\x1b[0m');
}

/**
 * Pfad zu einer Datei des Packages — lokal aus dem Nachbar-Repo, sonst aus den
 * installierten node_modules.
 */
const packageEntry = (file) => useLocalPackage
    ? resolve(localPackage, file)
    : resolve(import.meta.dirname, 'node_modules/@einundzwanzig/group', file);

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // Die API-Referenz unter /docs/api. Eigener Einstieg statt
                // eines Imports in app.js — Begruendung in der Datei selbst.
                'resources/js/scalar.js',
            ],
            refresh: true,
        }),
    ],
    resolve: {
        alias: {
            // `session` liegt nicht in der exports-Map des Packages und waere per
            // Deep-Import blockiert. Wir brauchen genau eine Funktion daraus
            // (loginWithExtension), um die welshman-Session mit dem bereits per
            // NIP-07 eingeloggten Vereinsnutzer zu fuellen — ohne den
            // NIP-98-Handoff und Redirect der nostrAuth-Komponente.
            //
            // Bewusst als Alias hier statt als Aenderung an der exports-Map:
            // einundzwanzig-group bleibt dadurch unberuehrt.
            '@einundzwanzig/group/session': packageEntry('js/session.ts'),
            '@einundzwanzig/group/auth-gate': packageEntry('js/auth-gate.ts'),
            // createRoom() und addRoomMember() fuer die Anlage des Antragsraums.
            '@einundzwanzig/group/groups': packageEntry('js/groups.ts'),
            // projectSupportTags(): markiert den Antragsraum im 39000 mit
            // ["t","project-support"] und ["i","proposal:<id>"]. Reines,
            // welshman-freies Modul — es haengt hier trotzdem nur am
            // dynamischen Import der Anlage, damit app.js schlank bleibt.
            '@einundzwanzig/group/roomCategories': packageEntry('js/roomCategories.ts'),
            // toast(): Die eingebettete Raum-Ansicht sagt damit, wo eine im
            // Ausschnitt fehlende Funktion weitergeht (Bild anhaengen). Bewusst
            // die Package-Funktion statt eines nachgebauten Event-Dispatch —
            // Flux' <flux:toast> erwartet eine exakte Detail-Form.
            '@einundzwanzig/group/toast': packageEntry('js/toast.ts'),
            // clearCache(): Beim Abmelden muss der Klartext-Cache des Raums aus
            // der IndexedDB. Solange die Insel laeuft, haelt sie die Verbindung
            // offen — nur clearCache() schliesst sie erst und loescht dann.
            '@einundzwanzig/group/storage': packageEntry('js/storage.ts'),
            '@einundzwanzig/group': packageEntry('js/index.ts'),
        },
    },
    build: {
        rollupOptions: {
            output: {
                // welshman ist gross (~950 KB) und aendert sich fast nie — eigener,
                // cache-stabiler Chunk, damit ein gewoehnliches Deploy nicht das
                // ganze SDK neu ausliefert.
                //
                // nostr-tools gehoert hier bewusst NICHT hinein, obwohl es im
                // Vorbild-Repo mit drin ist: Der Verein nutzt nostr-tools bereits
                // fuer Login und Zaps (resources/js/nostrLogin.js, nostrZap.js).
                // Gruppiert man beides, zieht app.js den welshman-Chunk mit — und
                // jede Vereinsseite laedt das komplette Chat-SDK. Am gebauten
                // Bundle nachgemessen, nicht vermutet.
                manualChunks(id) {
                    // nostr-tools zuerst und in einen EIGENEN Chunk: Es wird von
                    // beiden Seiten gebraucht (Verein: Login/Zaps, Insel: ueber
                    // welshman). Ohne diese Regel zieht Rollup es als geteilten
                    // Code in den welshman-Chunk — und app.js importiert damit
                    // das komplette Chat-SDK auf jeder Seite.
                    if (id.includes('/node_modules/nostr-tools/')) {
                        return 'nostr-tools';
                    }
                    // Krypto-Basis (@noble/*, @scure/*) ebenfalls eigenstaendig:
                    // Sie wird von beiden Seiten gebraucht — vom Zap-Code des
                    // Vereins ueber NDK und von welshman. Ohne eigene Regel legt
                    // Rollup sie in den welshman-Chunk, und app.js importiert
                    // wegen eines einzigen `schnorr` das ganze Chat-SDK.
                    if (id.includes('/@noble/') || id.includes('/@scure/')) {
                        return 'crypto';
                    }
                    if (id.includes('/@welshman/')) {
                        return 'welshman';
                    }
                },
            },
        },
    },
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/storage/**',
                '**/bootstrap/cache/**',
                '**/.idea/**',
                '**/.junie/**',
                '**/.fleet/**',
                '**/.vscode/**',
            ],
        },
    },
});
