/**
 * Die API-Referenz fuer /api/v1 — aus dem eigenen Origin, nicht von einem
 * Dritt-CDN.
 *
 * Scrambles mitgelieferte View laedt `@scalar/api-reference` per
 * <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference">. Das
 * schickt die IP jedes Lesers an einen Dritten und zieht ein ungepinntes
 * „latest" ohne SRI — zwei Entscheidungen, die niemand getroffen hat. Hier
 * liegt das Paket stattdessen als devDependency fest (Version in
 * package-lock.json) und wird von Vite gehasht aus /build ausgeliefert.
 *
 * Der ESM-Einstieg des Pakets und NICHT `dist/browser/standalone.js`: Das
 * fertige Browser-Bundle waere der kuerzere Weg, aber es laesst sich mit dem
 * Vite-8-Bundler nicht uebersetzen. Rolldown bricht darin an einem Template-
 * Literal ab, dem unmittelbar ein Schluesselwort folgt (`toJSON`in r,
 * Byte 2276944) — gueltiges JavaScript, das sein Parser nicht annimmt.
 * Gemessen mit `npm run build`, nicht vermutet. Ueber den ESM-Einstieg baut
 * Vite die Referenz ordentlich in eigene Chunks; die laedt sie zur Laufzeit
 * relativ zu ihrer eigenen URL nach, also ebenfalls aus diesem Origin.
 *
 * Der Einstieg haengt bewusst nicht an app.js: Die Referenz laeuft auf genau
 * einer Seite und braucht Vue plus ~250 KB CSS, die sonst jede Vereinsseite
 * mittruege.
 *
 * EXAKT GEPINNT in package.json (`"@scalar/api-reference": "1.64.0"`, ohne
 * Caret) — als einzige Abhaengigkeit dieses Repos, und das hat einen Grund.
 * Die Referenz haengt drei Schutzschalter an Konfigurationsschluessel, die
 * nach OBEN durchfallen: `withDefaultFonts` ist
 * `z.boolean().optional().default(true).catch(true)`, `proxyUrl` ist optional.
 * Wird einer davon in einer Minor-Version umbenannt, greift wieder der
 * Vendor-Default — vierzehn Font-URLs auf fonts.scalar.com bzw. ein Proxy auf
 * proxy.scalar.com — und im ausgelieferten HTML stuende davon nichts, weil die
 * Referenz beides erst zur Laufzeit erzeugt. Ein `^1.64.0` haette diese
 * Umbenennung beim naechsten `npm install` still eingesammelt. Ein Update ist
 * damit eine Entscheidung: Version hochsetzen, Seite gegen einen Browser
 * messen (externe Requests muessen leer bleiben), dann uebernehmen.
 */
import { createApiReference } from '@scalar/api-reference';
import '@scalar/api-reference/style.css';

/*
 * Als globales `Scalar` und nicht als Export: So sieht die Seite genau die
 * Schnittstelle, die auch das Standalone-Bundle bereitstellt
 * (`Scalar.createApiReference`). Der Aufruf steht in
 * resources/views/docs/scalar.blade.php, weil erst dort das generierte
 * Dokument und die Renderer-Konfiguration vorliegen.
 */
window.Scalar = { createApiReference };
