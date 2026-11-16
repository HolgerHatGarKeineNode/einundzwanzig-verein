import { generateSecretKey, getPublicKey } from 'nostr-tools/pure'
import { nsecEncode } from 'nostr-tools/nip19'
import { test, expect, scenario } from './support/election.js'

/**
 * The portal's guard around window.nostr.js 0.7.0
 * (resources/views/partials/wnj-nsec-guard.blade.php), against the real
 * vendor file: no extension is installed in these tests.
 *
 * Jury run 2, M-2: the portal's own „Signer-App verbinden“ opens the vendor
 * dialog, whose field also takes an `nsec1…` and keeps it in plaintext in
 * localStorage['wnj:bunkerPointer']; a `#nostr-login=nsec1…` link does the
 * same without typing. Pinned here:
 *
 *   - a typed nsec never reaches the vendor (input, Enter and submit), the
 *     field is emptied and the German warning shows, at 390 and 1280 px;
 *   - an nsec pointer already in storage is purged on load;
 *   - a `#nostr-login=nsec1…` link stores nothing and gives no signer;
 *   - a `bunker://` link still goes through, up to the relay connection.
 *
 * Opening the dialog makes the vendor dial its nostrconnect relays (and, for
 * a bunker link, the bunker's relay). Those sockets are taken by the spec and
 * never reach the network; their URLs are what the bunker case checks.
 *
 * Mutation seen red (j2b, 2026-11-16): the @include of the guard removed from
 * resources/views/layouts/app.blade.php turns the four nsec cases red (the
 * typed nsec stays in the field; the seeded and the linked nsec end up in
 * wnj:bunkerPointer); the bunker case stays green. Without the guard, the
 * vendor answered getPublicKey() and signEvent() for a #nostr-login=nsec1…
 * link at once, with the link's key and without any dialog.
 */

const ELECTION = '/association/election'
const POINTER = 'wnj:bunkerPointer'
const WARNING = 'Kein nsec hier eintragen'

function throwawayNsec() {
    const sk = generateSecretKey()

    return { nsec: nsecEncode(sk), pubkey: getPublicKey(sk) }
}

/**
 * Take every WebSocket of the page and remember where it went. The socket is
 * never connected to a server and stays open without an answer, as a relay
 * that is slow to respond would; closing it makes the vendor warn.
 */
async function catchSockets(page) {
    const urls = []
    await page.routeWebSocket(() => true, (socket) => {
        urls.push(socket.url())
    })

    return urls
}

async function openVendorDialog(page) {
    await page.locator('[data-location="election"] button').filter({ visible: true }).click()
    await expect(page.locator('#guest-signer-help')).toBeFocused()
    await page.locator('#guest-signer-help').getByRole('button', { name: 'Signer-App verbinden' }).click()
    const field = page.locator('input[placeholder*="bunker://"]')
    await expect(field).toBeVisible({ timeout: 10000 })

    return field
}

const storedPointer = (page) => page.evaluate((key) => localStorage.getItem(key), POINTER)

for (const viewport of [{ width: 390, height: 844 }, { width: 1280, height: 720 }]) {
    test(`a typed nsec is neither kept nor stored, and the German warning shows (${viewport.width} px)`, async ({ page }) => {
        await page.setViewportSize(viewport)
        scenario('member')
        await catchSockets(page)
        const { nsec } = throwawayNsec()

        await page.goto(ELECTION)
        const field = await openVendorDialog(page)

        // Typed or pasted: the input event is stopped and the field emptied.
        await field.fill(nsec)
        await expect(field).toHaveValue('')
        const warning = page.locator('[data-portal-nsec-warning]')
        await expect(warning).toBeVisible()
        await expect(warning).toContainText(WARNING)

        const box = await warning.boundingBox()
        expect(box.x).toBeGreaterThanOrEqual(0)
        expect(box.x + box.width).toBeLessThanOrEqual(viewport.width)
        expect(box.y + box.height).toBeLessThanOrEqual(viewport.height)

        // A value that got in without an input event (autofill, a script):
        // Enter and submit empty the field as well. The vendor itself reads
        // only the value its input listener mirrored, which the guard keeps
        // empty; these two pin that the nsec does not stay on screen either
        // (red with the guard reduced to 'input', j2b).
        await field.evaluate((input, value) => { input.value = value }, nsec)
        await field.press('Enter')
        await expect(field).toHaveValue('')

        await field.evaluate((input, value) => { input.value = value }, nsec)
        await field.evaluate((input) => input.form.requestSubmit())
        await expect(field).toHaveValue('')

        await page.waitForTimeout(500)
        expect(await storedPointer(page)).toBeNull()

        await page.reload()
        expect(await storedPointer(page)).toBeNull()
        expect(await page.evaluate(() => window.nostr?.isWnj === true)).toBe(true)
    })
}

test('an nsec pointer already in storage is purged on load', async ({ page }) => {
    scenario('member')
    await catchSockets(page)
    const { nsec } = throwawayNsec()

    await page.goto(ELECTION)
    await page.evaluate(([key, value]) => localStorage.setItem(key, value), [POINTER, nsec])

    await page.reload()
    expect(await storedPointer(page)).toBeNull()
    expect(await page.evaluate(() => window.nostr?.isWnj === true)).toBe(true)

    // The login button finds no signer: the German help, not a signature.
    await page.locator('[data-location="election"] button').filter({ visible: true }).click()
    await expect(page.locator('#guest-signer-help')).toBeFocused()

    await page.reload()
    expect(await storedPointer(page)).toBeNull()
})

test('a #nostr-login=nsec1… link stores nothing and gives no signer', async ({ page }) => {
    scenario('member')
    await catchSockets(page)
    const { nsec, pubkey } = throwawayNsec()

    await page.goto(`${ELECTION}#nostr-login=${nsec}`)
    await expect(page.locator('[data-location="election"] button').filter({ visible: true })).toBeVisible()

    expect(new URL(page.url()).hash).toBe('')
    expect(await storedPointer(page)).toBeNull()

    // With the nsec taken, the vendor would answer at once with its pubkey.
    const answer = await page.evaluate(() => Promise.race([
        window.nostr.getPublicKey(),
        new Promise((resolve) => setTimeout(() => resolve('no answer'), 1500)),
    ]))
    expect(answer).not.toBe(pubkey)
    expect(answer).toBe('no answer')
    expect(await storedPointer(page)).toBeNull()

    await page.reload()
    expect(await storedPointer(page)).toBeNull()
})

test('a bunker:// link still goes through to the bunker relay', async ({ page }) => {
    scenario('member')
    const sockets = await catchSockets(page)
    const bunker = `bunker://${getPublicKey(generateSecretKey())}?relay=${encodeURIComponent('wss://bunker.relay.invalid')}`

    await page.goto(ELECTION)
    const field = await openVendorDialog(page)

    await field.fill(bunker)
    await expect(field).toHaveValue(bunker)
    await field.press('Enter')

    await expect.poll(() => sockets.some((url) => url.startsWith('wss://bunker.relay.invalid')), { timeout: 10000 }).toBe(true)
    await expect(page.locator('[data-portal-nsec-warning]')).toHaveCount(0)
    expect(await storedPointer(page)).toBeNull()
})
