import { execFileSync } from 'node:child_process'
import { test, expect } from '@playwright/test'
import { getPublicKey } from 'nostr-tools/pure'
import { hexToBytes } from 'nostr-tools/utils'
import { installLocalNip07, SUBMITTER_SEC } from './support/local-nip07.js'
import { expectColdStart } from './support/chat-island.js'
import { resetRoom } from './support/reset-room.js'

/**
 * Das aufklappbare Chat-Band auf der Antragsseite (show.blade.php).
 *
 * Vier Fragen, die nur ein echter Browser beantwortet:
 *   1. Traegt der Aufruf der Seite (angemeldet, Raum existiert, Chat NICHT
 *      angeklickt) irgendeinen Chat-Chunk nach — auf BEIDEN Viewports?
 *   2. Startet das Band auf dem Telefon zu, auf dem Desktop offen? Ist die
 *      Kopfzeile auf dem Telefon eine 44px-Trefferflaeche, die aufklappt?
 *   3. Uebersteht das Auf-/Zuklappen eine Livewire-Aktualisierung (Morph)?
 *      `wire:ignore.self` am Schalter, `style="display:none"` statt
 *      `x-cloak` am Panel — beide Entscheidungen zielen genau hierauf.
 *
 * Voraussetzung (siehe chat-feed-embed.spec.js fuer den vollen Aufbau):
 *   ./scripts/zooid-testserver.sh start
 *   DB_DATABASE=/tmp/e2e-verein.sqlite NOSTR_SPACE_URL=ws://localhost:3341/ \
 *     php artisan serve --port=8137
 */

const PROPOSAL_SLUG = process.env.E2E_PROPOSAL_SLUG ?? 'lightning-watchtower-fur-mitglieder'
/** Wegwerf-Vorstand, siehe chat-feed-board.spec.js. */
const BOARD_SEC = process.env.E2E_BOARD_SEC ?? 'a08bacac006cbee5ad0d8cc685e8f0cf375306345367c4793393c19d1ccdffb4'

const MOBILE = { width: 390, height: 844 }
const DESKTOP = { width: 1280, height: 800 }
const DESKTOP_WIDE = { width: 1440, height: 900 }
const DESKTOP_HD = { width: 1920, height: 1080 }
const REFLOW = { width: 320, height: 700 }

/**
 * Frischer, fest verdrahteter Schluessel ohne EinundzwanzigPleb-Zeile in der
 * Fixtur — weder Vorstand noch Einreicher. `getPleb()` liefert fuer ihn null,
 * `viewContact` also false (siehe ProjectProposalPolicy). Bewusst deterministisch
 * statt bei jedem Lauf neu generiert.
 */
const STRANGER_SEC = '7922dc52b2bea10d63dbd135550335758e02b02c794971d355c8b32b99b8634c'

/** Fester Raum-Stand der Fixtur — fuer die Zustaende OHNE Raum zwischenzeitlich
 *  genullt (resetRoom) und danach hierauf zurueckgesetzt. */
const ROOM_ID = 'p4e07408562be'
const ROOM_CREATED_AT = '2026-07-20 00:32:27'

function restoreRoom() {
    execFileSync('sqlite3', [DB_PATH, `
        UPDATE project_proposals SET nostr_group_h = '${ROOM_ID}', nostr_group_created_at = '${ROOM_CREATED_AT}'
        WHERE slug = '${PROPOSAL_SLUG}';
    `])
}

/**
 * Kein Vorfahre des Chat-Bands (bis zum body) darf `overflow: hidden` tragen.
 * Das ist die Invariante hinter dem Kommentar "Bewusst KEIN overflow-hidden
 * zum Runden der Kopfzeile" im Blade: Die Insel legt Emoji-Panel, Mention-Liste
 * und Scroll-Knopf absolut ueber den Verlauf, nicht in einem Portal/Popover —
 * ein clippender Vorfahre schnitte sie ab. Eine Bounding-Box-Pruefung auf den
 * tatsaechlichen Overlays waere flaky (haengt an Profil-Daten vom Relay); die
 * CSS-Kette ist der deterministische Test fuer genau dieselbe Aussage.
 */
async function overflowHiddenAncestors(page, selector) {
    return page.evaluate((sel) => {
        const hits = []
        let el = document.querySelector(sel)
        while (el && el !== document.body) {
            const cs = getComputedStyle(el)
            if (cs.overflow === 'hidden' || cs.overflowX === 'hidden' || cs.overflowY === 'hidden') {
                hits.push(`${el.tagName}.${[...el.classList].join('.')} => overflow:${cs.overflow} x:${cs.overflowX} y:${cs.overflowY}`)
            }
            el = el.parentElement
        }
        return hits
    }, selector)
}

/**
 * Wartet, bis das Layout steht — Voraussetzung fuer jede Positionsmessung.
 *
 * Bis zum Profil-Relay-Fix lagen zwischen Login und Messung ~21 Sekunden, weil
 * `/api/nostr/profile/{pubkey}` synchron auf vier oeffentliche Relays wartete.
 * Diese Wartezeit hat das Layout unbeabsichtigt „stabilisiert". Ohne sie messen
 * die Positionstests, waehrend das Projektbild noch laedt und die Seitenspalte
 * noch umbricht — mal trifft es den einen Test, mal den anderen.
 *
 * Bewusst KEIN `waitForTimeout`: Das waere dieselbe Zufaelligkeit mit kuerzerer
 * Zahl. Gewartet wird auf die zwei Dinge, die die Hoehen tatsaechlich bewegen —
 * fertig geladene Bilder und zwei aufeinanderfolgende gleiche Messungen.
 */
async function waitForStableLayout(page, locator) {
    await page.evaluate(() => Promise.all(
        Array.from(document.images)
            .filter((img) => !img.complete)
            .map((img) => new Promise((resolve) => {
                img.addEventListener('load', resolve, { once: true })
                img.addEventListener('error', resolve, { once: true })
            })),
    ))

    let last = null
    for (let i = 0; i < 20; i++) {
        const box = await locator.boundingBox()
        if (box && last !== null && Math.abs(box.y - last) < 1) {
            return box
        }
        last = box?.y ?? null
        await page.waitForTimeout(100)
    }

    return locator.boundingBox()
}

/** Dieselbe Menge Fremdanfragen wie in chat-feed-board.spec.js Test 2. */
const CHAT_ASSET_PATTERN = /welshman|emojibase|\/assets\/js-|storage-|session-|groups-/

/** Die DB-KOPIE aus dem Bericht — niemals die Arbeitsdatenbank. */
const DB_PATH = process.env.E2E_DB_PATH ?? '/tmp/e2e-verein.sqlite'

test.describe.configure({ timeout: 120000 })

for (const [label, viewport] of Object.entries({ mobile: MOBILE, desktop: DESKTOP })) {
    test(`Punkt 1: kein Chat-Chunk beim blossen Aufruf (${label}, ${viewport.width}px)`, async ({ page }) => {
        const chatRequests = []
        let websockets = 0

        page.on('request', (r) => {
            if (CHAT_ASSET_PATTERN.test(r.url())) {
                chatRequests.push(r.url())
            }
        })
        page.on('websocket', (ws) => {
            websockets++
            chatRequests.push(`[websocket] ${ws.url()}`)
        })

        await page.setViewportSize(viewport)
        await installLocalNip07(page)

        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await expectColdStart(page)
        // Unterhalb von lg steckt die Sidebar mit dem Login-Knopf off-canvas
        // (fixed, ausserhalb des Viewports) hinter dem Hamburger-Menue.
        if (label === 'mobile') {
            await page.getByRole('button', { name: 'Menü öffnen' }).click()
        }
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()
        await expect(page.getByText('Chat zum Antrag')).toBeVisible({ timeout: 60000 })

        // Zeit fuer traege Ladung: Ein Chunk, der beim Boot statt beim Klick
        // nachgezogen wird, waere spaetestens jetzt unterwegs.
        await page.waitForTimeout(3000)

        expect(chatRequests, `Chat-Assets ohne Klick geladen (${label}): ${chatRequests.join(' | ')}`).toEqual([])
        expect(websockets, `WebSocket ohne Klick geoeffnet (${label})`).toBe(0)
    })
}

test('Punkt 2: Band startet auf dem Telefon zu, auf dem Desktop offen', async ({ page }) => {
    await installLocalNip07(page)

    // ── Mobil: zu, Kopfzeile ist eine >=44px-Trefferflaeche, klappt auf ──────
    await page.setViewportSize(MOBILE)
    await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
    // Unterhalb von lg steckt die Sidebar mit dem Login-Knopf off-canvas
    // (fixed, ausserhalb des Viewports) hinter dem Hamburger-Menue.
    await page.getByRole('button', { name: 'Menü öffnen' }).click()
    await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()

    const header = page.getByRole('button', { name: 'Chat zum Antrag' })
    await expect(header).toBeVisible({ timeout: 60000 })

    await expect(header).toHaveAttribute('aria-expanded', 'false')
    const panel = page.locator('#chat-band-panel')
    await expect(panel).toBeHidden()

    const box = await header.boundingBox()
    expect(box, 'Kopfzeile hat keine Bounding-Box').not.toBeNull()
    expect(box.height, `Kopfzeile ist nur ${box.height}px hoch, verlangt sind >=44px`).toBeGreaterThanOrEqual(44)

    await page.screenshot({
        path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/chat-band-mobile-closed.png',
        fullPage: false,
    })

    await header.click()
    await expect(header).toHaveAttribute('aria-expanded', 'true')
    await expect(panel).toBeVisible()

    await page.screenshot({
        path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/chat-band-mobile-open.png',
        fullPage: false,
    })

    // ── Desktop: derselbe Login, neuer Viewport → soll offen starten ────────
    await page.setViewportSize(DESKTOP)
    await page.reload()
    await expect(page.getByRole('button', { name: 'Chat zum Antrag' })).toBeVisible({ timeout: 60000 })

    const desktopHeader = page.getByRole('button', { name: 'Chat zum Antrag' })
    await expect(desktopHeader).toHaveAttribute('aria-expanded', 'true')
    await expect(panel).toBeVisible()

    await page.screenshot({
        path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/chat-band-desktop-open.png',
        fullPage: false,
    })
})

test('Punkt 3: Band bleibt nach einer Livewire-Aktualisierung auf-/zuklappbar', async ({ page }) => {
    // Der Trigger fuer die Aktualisierung ist eine Stimmabgabe — die Komponente
    // bietet keine Rueckgaengig-Aktion. Auf der DB-KOPIE (nie der
    // Arbeitsdatenbank) wird die Stimme dieses Wegwerf-Vorstands darum vor
    // jedem Lauf entfernt, sonst zeigt die Seite ab dem zweiten Lauf nur noch
    // "Du hast zugestimmt" und der Test kann die Aktualisierung nicht ausloesen.
    const boardPubkey = getPublicKey(hexToBytes(BOARD_SEC))
    execFileSync('sqlite3', [DB_PATH, `
        DELETE FROM votes
        WHERE einundzwanzig_pleb_id IN (SELECT id FROM einundzwanzig_plebs WHERE pubkey = '${boardPubkey}')
          AND project_proposal_id IN (SELECT id FROM project_proposals WHERE slug = '${PROPOSAL_SLUG}');
    `])

    await page.setViewportSize(DESKTOP)
    await installLocalNip07(page, BOARD_SEC)

    await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
    await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()

    const header = page.getByRole('button', { name: 'Chat zum Antrag' })
    const panel = page.locator('#chat-band-panel')
    await expect(header).toBeVisible({ timeout: 60000 })

    // Vorbedingung: Desktop startet offen.
    await expect(header).toHaveAttribute('aria-expanded', 'true')
    await expect(panel).toBeVisible()

    // Vorbedingung fuer die Stimmabgabe: Dieses Vorstandsmitglied darf noch
    // abstimmen. Sonst zeigt die Seitenspalte "Du hast zugestimmt" statt des
    // Knopfes, und der Test triggert keine Aktualisierung.
    const voteTrigger = page.getByRole('button', { name: 'Zustimmen' }).first()
    await expect(voteTrigger, 'Kein Zustimmen-Knopf sichtbar — hat dieses Testkonto schon abgestimmt?')
        .toBeVisible({ timeout: 15000 })

    // ── Die Livewire-Aktualisierung ausloesen (Stimmabgabe) ──────────────────
    await voteTrigger.click()
    const dialog = page.locator('dialog[open]')
    await expect(dialog).toBeVisible({ timeout: 10000 })
    await dialog.getByRole('button', { name: 'Zustimmen' }).click()
    await expect(page.getByText('Deine Stimme wurde gezählt.')).toBeVisible({ timeout: 15000 })
    await expect(dialog).toBeHidden()

    // ── Beweis, dass der Morph den offenen Zustand NICHT zurueckgesetzt hat ──
    // Genau das ist die im Auftrag genannte Regressionsgefahr: Ohne
    // wire:ignore.self / display:none stuende hier "false", obwohl das Panel
    // sichtbar offen dasteht.
    await expect(header).toHaveAttribute('aria-expanded', 'true')
    await expect(panel).toBeVisible()

    // ── Und es laesst sich danach noch normal zu- und aufklappen ────────────
    await header.click()
    await expect(header).toHaveAttribute('aria-expanded', 'false')
    await expect(panel).toBeHidden()

    await header.click()
    await expect(header).toHaveAttribute('aria-expanded', 'true')
    await expect(panel).toBeVisible()

    // ── Regression 4c aus dem Auftrag: Bleibt die Kartenreihenfolge nach dem
    // Morph korrekt? Auf lg soll die DOM-/Sichtreihenfolge unveraendert sein:
    // Deine Stimme -> Vorstands-Panel -> Vorstandsentscheidung -> Stimmungsbild
    // -> Kontakt. Ein Morph, der die order-Klassen der Karten verwuerfelt,
    // faellt hier durch.
    const cardTitles = ['Deine Stimme', 'Vorstand', 'Vorstandsentscheidung', 'Stimmungsbild der Mitglieder', 'Kontakt zum Einreicher']
    const ys = await Promise.all(cardTitles.map(async (t) => {
        const box = await page.getByText(t, { exact: true }).first().boundingBox()
        return box?.y ?? null
    }))
    expect(ys.every((y) => y !== null), `Nicht alle Karten gefunden: ${JSON.stringify(ys)}`).toBe(true)
    const sorted = [...ys].sort((a, b) => a - b)
    expect(ys, `Kartenreihenfolge nach dem Morph durcheinander: ${JSON.stringify(ys)}`).toEqual(sorted)
})

/**
 * ── Anhang: Ueberpruefung der Layout-Aenderungen A (Bandhoehe) und B (mobile
 * Reihenfolge) aus dem Bericht. Vier neue Zustaende, Mobile/Desktop-Vergleich,
 * die vom Autor genannten Regressionsgefahren und der 320px-Reflow.
 */

test.describe('Aenderung A: Band nimmt seine Hoehe aus dem Inhalt', () => {
    test('Raum vorhanden: ungeladen ~196px ohne Leerraum, geladen waechst deutlich, keine beschneidenden Vorfahren', async ({ page }) => {
        await page.setViewportSize(DESKTOP)
        await installLocalNip07(page)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()
        await expect(page.getByText('Chat zum Antrag')).toBeVisible({ timeout: 60000 })

        const panel = page.locator('#chat-band-panel')
        const band = panel.locator('xpath=..')
        await expect(panel).toBeVisible()

        const closedBox = await band.boundingBox()
        expect(closedBox, 'Band hat keine Bounding-Box').not.toBeNull()
        console.log(`Band-Hoehe ungeladen: ${closedBox.height}px`)
        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/band-room-unloaded.png',
        })
        // Der behobene Fehler war ~350px Panel- / ~420px Gesamthoehe durch die
        // leere Faktenspalte, die die Grid-Zeile offenhielt. Grosszuegige
        // Schwelle, damit der Test nicht an Pixel-Rundung haengt, aber jede
        // Rueckkehr zur alten Grid-Hoehe zuverlaessig durchfaellt.
        expect(closedBox.height, `Band ist ${closedBox.height}px hoch (ungeladen) — Grid-Regression?`).toBeLessThan(300)

        const loadButton = page.getByRole('button', { name: /Chat hier laden/i })
        await expect(loadButton).toBeVisible()
        await loadButton.click()
        const composer = page.getByLabel('Nachricht schreiben')
        await expect(composer).toBeVisible({ timeout: 60000 })

        const openBox = await band.boundingBox()
        console.log(`Band-Hoehe geladen: ${openBox.height}px`)
        expect(openBox.height, `Band waechst nach dem Laden nicht (${closedBox.height}px -> ${openBox.height}px)`)
            .toBeGreaterThan(closedBox.height + 200)
        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/band-room-loaded.png',
        })

        const hits = await overflowHiddenAncestors(page, '#chat-band-panel')
        expect(hits, `overflow-hidden auf einem Vorfahren des Panels: ${hits.join(' | ')}`).toEqual([])
    })
})

/**
 * ── Nachtrag: max-w-[68ch] am Insel-Container entfernt (show.blade.php,
 * uncommitted). Beschwerde: Auf einem ~1630px-Schirm klebte der geladene
 * Verlauf in einer 68-Zeichen-Spalte links, rechts blieb die halbe
 * Bandflaeche leer. Miss Verlauf und Composer GEGEN die Bandbreite — die
 * korrekte Grosse haengt am Band (das seinerseits an max-w-[1600px] des
 * Seiteninhalts haengt), nicht an einer festen Pixelzahl.
 */
test.describe('Nachtrag: Chat nutzt die Bandbreite (68ch-Deckel entfernt)', () => {
    for (const [label, viewport] of Object.entries({ '1920x1080': DESKTOP_HD, '1440x900': DESKTOP_WIDE })) {
        test(`Verlauf und Composer fast so breit wie das Band, kein Leerraum rechts (${label})`, async ({ page }) => {
            await page.setViewportSize(viewport)
            await installLocalNip07(page)
            await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
            await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()
            await expect(page.getByText('Chat zum Antrag')).toBeVisible({ timeout: 60000 })

            const panel = page.locator('#chat-band-panel')
            const band = panel.locator('xpath=..')
            const bandBox = await band.boundingBox()

            const loadButton = page.getByRole('button', { name: /Chat hier laden/i })
            await expect(loadButton).toBeVisible()
            await loadButton.click()
            const composer = page.getByLabel('Nachricht schreiben')
            await expect(composer).toBeVisible({ timeout: 60000 })

            const logBox = await page.locator('[role="log"]').boundingBox()
            // Die ganze Composer-ZEILE (Anhang-Knopf, Textfeld, Senden-Knopf), nicht
            // nur das Eingabefeld — sonst verdeckten die Icon-Knoepfe eine schmalere
            // tatsaechliche Breite.
            const composerRowBox = await composer.locator('xpath=..').boundingBox()

            const logRatio = logBox.width / bandBox.width
            const composerRatio = composerRowBox.width / bandBox.width
            console.log(`${label}: Band ${bandBox.width}px, Verlauf ${logBox.width}px (${(logRatio * 100).toFixed(1)}%), Composer ${composerRowBox.width}px (${(composerRatio * 100).toFixed(1)}%)`)

            // Gemessen: 97.3% (1920) / 96.0% (1440) — der Rest ist die Innenpolsterung
            // des Bands (px-4/px-5). 0.85 als Schwelle liegt klar darueber, aber weit
            // unter dem alten 68ch-Deckel (~750px bei 1920 waeren nur ~48%) — ein
            // Rueckfall auf den Deckel faellt hier zuverlaessig durch.
            expect(logRatio, `Verlauf nur ${(logRatio * 100).toFixed(1)}% der Bandbreite (${label}) — 68ch-Deckel zurueck?`)
                .toBeGreaterThan(0.85)
            expect(composerRatio, `Composer nur ${(composerRatio * 100).toFixed(1)}% der Bandbreite (${label}) — 68ch-Deckel zurueck?`)
                .toBeGreaterThan(0.85)

            // ── Nicht ins Gegenteil gekippt ──────────────────────────────────────
            const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
            const clientWidth = await page.evaluate(() => document.documentElement.clientWidth)
            expect(scrollWidth, `Horizontale Scrollleiste (${label}): scrollWidth ${scrollWidth} > clientWidth ${clientWidth}`)
                .toBeLessThanOrEqual(clientWidth)

            expect(composerRowBox.x + composerRowBox.width, `Composer laeuft ueber den rechten Bandrand hinaus (${label})`)
                .toBeLessThanOrEqual(bandBox.x + bandBox.width + 1)

            // Keine echte Leer-Raum-Fixtur verfuegbar (die einzige Raum-Fixtur traegt
            // 36 Nachrichten) — als naechstbester Beleg gegen "zerrissen": Eine
            // tatsaechlich gerenderte Nachrichtenzeile bleibt INNERHALB des
            // Verlauf-Containers, nichts ragt bei voller Breite heraus.
            const firstRow = page.locator('[id^="msg-"]').first()
            await expect(firstRow).toBeVisible()
            const rowBox = await firstRow.boundingBox()
            expect(rowBox.x, `Nachrichtenzeile ragt links aus dem Verlauf (${label})`).toBeGreaterThanOrEqual(logBox.x - 1)
            expect(rowBox.x + rowBox.width, `Nachrichtenzeile ragt rechts aus dem Verlauf (${label})`)
                .toBeLessThanOrEqual(logBox.x + logBox.width + 1)

            if (label === '1920x1080') {
                await page.screenshot({
                    path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/nachtrag-1920-loaded.png',
                })
            }
        })
    }
})

test.describe('Aenderung A: Zustaende ohne Raum', () => {
    test.beforeAll(() => resetRoom(PROPOSAL_SLUG, DB_PATH))
    test.afterAll(() => restoreRoom())

    test('Vorstand ohne Raum: Band offen, "Chatraum anlegen" sichtbar', async ({ page }) => {
        await page.setViewportSize(DESKTOP)
        await installLocalNip07(page, BOARD_SEC)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()

        const header = page.getByRole('button', { name: 'Chat zum Antrag' })
        await expect(header).toBeVisible({ timeout: 60000 })
        await expect(header).toHaveAttribute('aria-expanded', 'true')
        await expect(page.getByRole('button', { name: /Chatraum anlegen/i })).toBeVisible()
        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/band-no-room-board.png',
        })
    })

    /**
     * War FUND, jetzt Regressionstest (siehe Bericht): Fuer einen Einreicher
     * ohne Raum blieb das Band komplett unsichtbar, obwohl das Blade eine
     * "Der Vorstand legt den Raum bei Nachfragen an."-Zeile fuer genau diesen Fall vorsieht.
     *
     * Ursache war das aeussere Gate `canSeeChatRoom || canCreateChatRoom`:
     * `canSeeChatRoom` verlangt `hasNostrGroup()`, `canCreateChatRoom`
     * Vorstandsmitgliedschaft — ein Einreicher ohne Raum und ohne
     * Vorstandsstatus erfuellte keines von beiden, das @if war fuer diese
     * Kombination nie wahr. Fix (uncommitted): neues, weiter gefasstes Gate
     * `canSeeChatSection` (= `viewContact` = Einreicher ODER Vorstand) ersetzt
     * die Oder-Verknuepfung der beiden engeren Rechte. War `test.fail()`, jetzt
     * eine echte Assertion — kippt sie zurueck auf rot, ist die Regression
     * zurueck.
     */
    test('Einreicher ohne Raum sieht jetzt das Band mit dem Anlege-Hinweis', async ({ page }) => {
        await page.setViewportSize(DESKTOP)
        await installLocalNip07(page, SUBMITTER_SEC)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()

        const header = page.getByRole('button', { name: 'Chat zum Antrag' })
        await expect(header).toBeVisible({ timeout: 60000 })
        await expect(header).toHaveAttribute('aria-expanded', 'true')
        await expect(page.getByText('Der Vorstand legt den Raum bei Nachfragen an')).toBeVisible()

        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/band-no-room-submitter-fixed.png',
            fullPage: true,
        })
    })

    /**
     * Gegenprobe zum obigen Fix: `viewContact` ist bewusst WEITER als die
     * beiden Einzelrechte, aber nicht grenzenlos offen. Ein Fremder — weder
     * Einreicher noch Vorstand — muss weiterhin nichts vom Chat-Abschnitt im
     * HTML bekommen. `toHaveCount(0)` statt `toBeHidden()`: das Gate steht
     * serverseitig um das gesamte Markup (siehe Blade-Kommentar "Das Gate
     * bleibt serverseitig"), es darf nicht bloss versteckt im DOM liegen.
     */
    test('Fremder (weder Vorstand noch Einreicher) sieht weiterhin nichts vom Chat-Abschnitt', async ({ page }) => {
        await page.setViewportSize(DESKTOP)
        await installLocalNip07(page, STRANGER_SEC)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()
        await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible({ timeout: 60000 })
        await page.waitForLoadState('networkidle')

        await expect(page.getByRole('button', { name: 'Chat zum Antrag' })).toHaveCount(0)
        await expect(page.getByText('Der Vorstand legt den Raum bei Nachfragen an')).toHaveCount(0)
        await expect(page.locator('#chat-band-panel')).toHaveCount(0)

        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/band-no-room-stranger.png',
            fullPage: true,
        })
    })
})

test.describe('Aenderung B: mobile Reihenfolge', () => {
    test('Antragstitel und Foerdersumme im ersten Bildschirm, Beschreibung vor "Deine Stimme"', async ({ page }) => {
        await page.setViewportSize(MOBILE)
        await installLocalNip07(page)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: 'Menü öffnen' }).click()
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()

        // ZUERST auf ein Merkmal warten, das es nur ANGEMELDET gibt. Die
        // Ueberschrift taugt dafuer nicht — die steht auch dem Gast da, und der
        // Test mass dann eine Seite ohne Abstimmungskarte („element(s) not
        // found"). Bis zum Profil-Relay-Fix verdeckten 21 Sekunden Wartezeit im
        // Login diesen Fehler; schnell genug, faellt er auf.
        await expect(page.getByText('Chat zum Antrag')).toBeVisible({ timeout: 60000 })

        const title = page.getByRole('heading', { level: 1 })
        await expect(title).toBeVisible({ timeout: 60000 })
        // Die Seitenspalte wird weiter unten gemessen — sie muss da sein, BEVOR
        // irgendeine Position genommen wird, sonst liefert boundingBox() null.
        await expect(page.getByText('Deine Stimme', { exact: true })).toBeVisible({ timeout: 60000 })
        await expect(page.locator('.prose')).toBeVisible()

        const titleBox = await waitForStableLayout(page, title)
        const fundingBox = await page.getByText('500.000 Sats').boundingBox()

        console.log(`Titel y=${titleBox.y}px, Foerdersumme y=${fundingBox.y}px`)
        // Grosszuegige Schwellen (Autor nennt ~142px/~230px als Zielwerte) —
        // die Aussage, die zaehlt, ist "im ersten Bildschirm" (844px hoch) UND
        // deutlich vor der alten ~480px/~600px-Regression durch die leere
        // Grid-Zeile im Chat-Band.
        expect(titleBox.y, `Titel bei ${titleBox.y}px — nicht mehr im ersten Bildschirm`).toBeLessThan(300)
        expect(fundingBox.y, `Foerdersumme bei ${fundingBox.y}px — nicht mehr im ersten Bildschirm`).toBeLessThan(400)

        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/mobile-order-first-screen.png',
        })

        // Beschreibung (Hauptspalte, Ende) muss VOR "Deine Stimme" (Seitenspalte,
        // Anfang) in der visuellen/DOM-Reihenfolge stehen — sonst ist die
        // order-Entfernung aus Aenderung B nicht das, was der Kommentar behauptet.
        const descriptionBox = await page.locator('.prose').boundingBox()
        const voteCardBox = await page.getByText('Deine Stimme', { exact: true }).boundingBox()
        expect(descriptionBox, 'Beschreibung (.prose) nicht gefunden').not.toBeNull()
        expect(voteCardBox, '"Deine Stimme"-Karte nicht gefunden').not.toBeNull()
        expect(descriptionBox.y, `Beschreibung (${descriptionBox.y}px) steht NICHT vor "Deine Stimme" (${voteCardBox.y}px)`)
            .toBeLessThan(voteCardBox.y)

        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/mobile-full-order.png',
            fullPage: true,
        })
    })
})

test.describe('Desktop unveraendert (Zweispalter, Kartenreihenfolge, Sticky)', () => {
    for (const [label, viewport] of Object.entries({ '1280px': DESKTOP, '1440px': DESKTOP_WIDE })) {
        test(`Chat zuerst, dann Deine-Stimme -> Vorstand -> Entscheidung -> Stimmungsbild -> Kontakt; Spalte klebt bewusst nicht (${label})`, async ({ page }) => {
            await page.setViewportSize(viewport)
            // BOARD_SEC: nur so sind alle 5 Karten gleichzeitig sichtbar
            // (Vorstands-Panel + Kontakt verlangen Vorstandsmitgliedschaft).
            await installLocalNip07(page, BOARD_SEC)
            await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
            await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()
            await expect(page.getByText('Chat zum Antrag')).toBeVisible({ timeout: 60000 })

            // Zweispalter: Hauptspalte links, Seitenspalte rechts.
            const mainBox = await page.locator('.prose').boundingBox()
            const sideBox = await page.getByText('Deine Stimme', { exact: true }).boundingBox()
            expect(sideBox.x, 'Seitenspalte steht nicht rechts von der Hauptspalte').toBeGreaterThan(mainBox.x + 200)

            const cardTitles = ['Chat zum Antrag', 'Deine Stimme', 'Vorstand', 'Vorstandsentscheidung', 'Stimmungsbild der Mitglieder', 'Kontakt zum Einreicher']
            const ys = await Promise.all(cardTitles.map(async (t) => {
                const box = await page.getByText(t, { exact: true }).first().boundingBox()
                return box?.y ?? null
            }))
            expect(ys.every((y) => y !== null), `Nicht alle Karten gefunden (${label}): ${JSON.stringify(ys)}`).toBe(true)
            const sorted = [...ys].sort((a, b) => a - b)
            expect(ys, `Kartenreihenfolge auf Desktop (${label}) weicht ab: ${JSON.stringify(ys)}`).toEqual(sorted)

            // Die Seitenspalte klebt bewusst NICHT mehr (`lg:sticky lg:top-6` ist
            // entfernt): Mit dem Chat als erster Karte ist sie fuer jede Rolle, die
            // ihn ueberhaupt sieht, hoeher als ein Laptop-Viewport. Eine Sticky-Box,
            // die hoeher ist als der Viewport, klebt mit dem KOPF oben fest — ihr
            // unteres Ende ist durch Scrollen nie erreichbar, Stimmungsbild und
            // Kontakt waeren dauerhaft abgeschnitten. Das ist schlechter als kein
            // Sticky. Der Test haelt die Entscheidung fest, damit sie nicht
            // versehentlich zurueckgedreht wird.
            const chatCard = page.getByText('Chat zum Antrag').first()
            const columnIsSticky = await page.locator('div.lg\\:sticky.lg\\:top-6').count()
            expect(columnIsSticky, 'Seitenspalte klebt wieder — siehe Kommentar: hoeher als der Viewport, unteres Ende unerreichbar').toBe(0)

            // Der Chat ist die ERSTE Karte der Spalte, ueber "Deine Stimme".
            const chatY = (await chatCard.boundingBox()).y
            const voteY = (await page.getByText('Deine Stimme', { exact: true }).boundingBox()).y
            console.log(`Chat y=${Math.round(chatY)}px, Deine Stimme y=${Math.round(voteY)}px`)
            expect(chatY, 'Chat steht nicht ueber "Deine Stimme"').toBeLessThan(voteY)

            // Und er steht in der SEITENSPALTE, nicht wieder ueber der vollen
            // Breite: linke Kante buendig mit "Deine Stimme", nicht mit der
            // Hauptspalte. Ohne diese Pruefung waere ein Rueckfall auf das alte
            // Vollbreiten-Band still gruen — der stand ja auch ueber dem Voting.
            const chatX = (await chatCard.boundingBox()).x
            console.log(`Chat x=${Math.round(chatX)}px, Seitenspalte x=${Math.round(sideBox.x)}px, Hauptspalte x=${Math.round(mainBox.x)}px`)
            expect(Math.abs(chatX - sideBox.x), `Chat sitzt bei x=${Math.round(chatX)}, Seitenspalte bei ${Math.round(sideBox.x)}`)
                .toBeLessThan(40)

            await page.screenshot({
                path: `/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/desktop-${label}-sticky.png`,
            })
        })
    }
})

test.describe('Regressionen laut Auftrag (Punkt 4)', () => {
    test('4a) "Chat oeffnen" sitzt auf Desktop rechts, nicht volle Breite, min. 44px hoch', async ({ page }) => {
        await page.setViewportSize(DESKTOP)
        await installLocalNip07(page)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()
        await expect(page.getByText('Chat zum Antrag')).toBeVisible({ timeout: 60000 })

        const panel = page.locator('#chat-band-panel')
        const panelBox = await panel.boundingBox()
        const chatButton = page.getByRole('link', { name: /Chat öffnen/i })
        await expect(chatButton).toBeVisible()
        const buttonBox = await chatButton.boundingBox()

        // Volle Kartenbreite — anders als frueher im Band. Seit der Chat in der
        // Seitenspalte sitzt (320px, ab xl 384px), stuende ein schmaler Knopf
        // entweder gequetscht neben dem Datum oder verwaist auf eigener Zeile.
        expect(buttonBox.width, 'Knopf fuellt die Kartenbreite nicht aus')
            .toBeGreaterThan(panelBox.width - 60)
        // min-h-11 = 44px; die Beschriftung braucht die volle Hoehe fuer die
        // Zentrierung, nicht mehr Hoehe als noetig (sonst haengt Text oben).
        expect(buttonBox.height, `Knopf ist ${buttonBox.height}px hoch, min-h-11 verlangt >=44px`)
            .toBeGreaterThanOrEqual(44)

        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/regression-4a-button-desktop.png',
        })
    })

    test('4a) "Chat oeffnen" ist auf Mobil volle Breite', async ({ page }) => {
        // Eigene Seite/Session statt Umschalten innerhalb eines Laufs: Ein
        // Viewport-Wechsel Desktop->Mobil auf derselben eingeloggten Seite lässt
        // die Sidebar (Transition) kurz Pointer-Events abfangen — flaky. Frischer
        // Login auf MOBILE von Anfang an ist deterministisch.
        await page.setViewportSize(MOBILE)
        await installLocalNip07(page)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: 'Menü öffnen' }).click()
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()

        const mobileHeader = page.getByRole('button', { name: 'Chat zum Antrag' })
        await expect(mobileHeader).toBeVisible({ timeout: 60000 })
        if ((await mobileHeader.getAttribute('aria-expanded')) === 'false') {
            await mobileHeader.click()
        }
        const mobileButton = page.getByRole('link', { name: /Chat öffnen/i })
        await expect(mobileButton).toBeVisible()
        const mobilePanelBox = await page.locator('#chat-band-panel').boundingBox()
        const mobileButtonBox = await mobileButton.boundingBox()
        expect(mobileButtonBox.width, 'Knopf ist auf Mobil NICHT volle Breite')
            .toBeGreaterThan(mobilePanelBox.width - 60)

        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/regression-4a-button-mobile.png',
        })
    })

    test('4b) Verlauf und Composer nutzen die Bandbreite, keine horizontale Scrollleiste', async ({ page }) => {
        await page.setViewportSize(DESKTOP_WIDE)
        await installLocalNip07(page)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()
        await expect(page.getByText('Chat zum Antrag')).toBeVisible({ timeout: 60000 })

        const loadButton = page.getByRole('button', { name: /Chat hier laden/i })
        await expect(loadButton).toBeVisible()
        await loadButton.click()
        const composer = page.getByLabel('Nachricht schreiben')
        await expect(composer).toBeVisible({ timeout: 60000 })
        // Der Verlauf waechst noch, waehrend Nachrichten und Avatare eintrudeln —
        // erst messen, wenn die Composer-Zeile zweimal an derselben Stelle steht.
        await waitForStableLayout(page, composer)

        // Frueher stand hier eine Obergrenze von 750px (der 68ch-Deckel). Die
        // ist gefallen: Zeilen und Eingabefeld des Packages bringen selbst keine
        // Begrenzung mit, ein Deckel liess auf breiten Schirmen die halbe
        // Bandflaeche leer. Jetzt gilt die Gegenrichtung — der Composer muss den
        // Grossteil des Bands ausfuellen.
        //
        // Bewusst NICHT ueber `.max-w-[68ch]` gesucht: Diese Klasse gibt es an
        // der Insel nicht mehr, `.first()` traf danach die Ueberschrift und der
        // Test war still gruen, ohne noch irgendetwas am Chat zu pruefen.
        // Gemessen wird die Composer-ZEILE gegen die Karte, nicht das Textfeld:
        // In der Zeile sitzen neben dem Feld noch Anhang- und Senden-Knopf mit je
        // ~44px. Im fruegeren Vollbreiten-Band fielen die neben 1568px nicht ins
        // Gewicht (Textfeld 97%), in einer 382px-Karte fressen sie ein Viertel.
        // Ein Schwellwert auf dem Textfeld misst dort die Knopfgroesse, nicht das
        // Layout — gemessen: Zeile 342/382px, Textfeld 246px, kein Ueberlauf.
        const panelBox = await page.locator('#chat-band-panel').boundingBox()
        const rowBox = await composer.locator('xpath=ancestor::*[3]').boundingBox()
        const composerBox = await composer.boundingBox()
        const ratio = rowBox.width / panelBox.width
        console.log(`Composer-Zeile ${Math.round(rowBox.width)}px / Karte ${Math.round(panelBox.width)}px = ${(ratio * 100).toFixed(0)}%, Textfeld ${Math.round(composerBox.width)}px`)
        expect(ratio, `Composer-Zeile nutzt nur ${(ratio * 100).toFixed(0)}% der Kartenbreite`)
            .toBeGreaterThan(0.8)
        // Untergrenze fuers Textfeld selbst: darunter wird das Schreiben zur Qual.
        expect(composerBox.width, `Textfeld nur ${Math.round(composerBox.width)}px breit`)
            .toBeGreaterThan(180)

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
        const clientWidth = await page.evaluate(() => document.documentElement.clientWidth)
        expect(scrollWidth, `Horizontale Scrollleiste: scrollWidth ${scrollWidth} > clientWidth ${clientWidth}`)
            .toBeLessThanOrEqual(clientWidth)
    })
})

test.describe('Reflow bei 320px (WCAG 1.4.10)', () => {
    test('keine horizontale Scrollleiste, Meta-Zeile wickelt statt zu ueberlaufen', async ({ page }) => {
        await page.setViewportSize(REFLOW)
        await installLocalNip07(page)
        await page.goto(`/association/project-support/${PROPOSAL_SLUG}`)
        await page.getByRole('button', { name: 'Menü öffnen' }).click()
        await page.getByRole('button', { name: /Mit Nostr verbinden/i }).first().click()
        await expect(page.getByText('Chat zum Antrag')).toBeVisible({ timeout: 60000 })

        const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth)
        const clientWidth = await page.evaluate(() => document.documentElement.clientWidth)
        expect(scrollWidth, `Horizontale Scrollleiste bei 320px (Band zu): scrollWidth ${scrollWidth} > clientWidth ${clientWidth}`)
            .toBeLessThanOrEqual(clientWidth)

        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/reflow-320-closed.png',
            fullPage: true,
        })

        // Die neue Meta-Zeile (Zugang, Angelegt-am, Chat-oeffnen-Knopf) steckt
        // im aufgeklappten Panel — nur DANN kann sie ueberhaupt umbrechen oder
        // ueberlaufen. Das Band startet unter 768px zu, also erst oeffnen.
        const header = page.getByRole('button', { name: 'Chat zum Antrag' })
        await header.click()
        await expect(page.locator('#chat-band-panel')).toBeVisible()

        const scrollWidthOpen = await page.evaluate(() => document.documentElement.scrollWidth)
        const clientWidthOpen = await page.evaluate(() => document.documentElement.clientWidth)
        expect(scrollWidthOpen, `Horizontale Scrollleiste bei 320px (Band offen): scrollWidth ${scrollWidthOpen} > clientWidth ${clientWidthOpen}`)
            .toBeLessThanOrEqual(clientWidthOpen)

        // Nichts in der Meta-Zeile darf ueber den rechten Viewport-Rand hinausragen.
        const metaRow = page.locator('#chat-band-panel .flex.flex-wrap.items-center.gap-x-5').first()
        const metaBox = await metaRow.boundingBox()
        expect(metaBox.x + metaBox.width, `Meta-Zeile ragt ueber den rechten Rand hinaus (right=${metaBox.x + metaBox.width}, viewport=${REFLOW.width})`)
            .toBeLessThanOrEqual(REFLOW.width)

        await page.screenshot({
            path: '/tmp/claude-1000/-home-user-Code-einundzwanzig-verein/576545e5-100b-4a8e-a905-9e6c6ed70562/scratchpad/screenshots/reflow-320-open.png',
            fullPage: true,
        })
    })
})
