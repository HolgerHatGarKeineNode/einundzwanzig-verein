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
 *     field is emptied and the German warning shows; typed key by key, no
 *     fragment of it is left in any field, in what the vendor mirrored or in
 *     storage (gate j2b, D2);
 *   - the field and the whole warning stay inside the viewport at 390x844,
 *     1280x650, 1280x720 and 1366x768 (gate j2b, D1: the vendor's panel grew
 *     upward and pushed both above the top edge);
 *   - after a refused nsec, a pasted bunker:// link or NIP-05 address passes,
 *     and a reopened dialog takes a typed NIP-05 address;
 *   - an nsec pointer already in storage is purged on load;
 *   - a `#nostr-login=nsec1…` link stores nothing and gives no signer;
 *   - a `bunker://` link still goes through, up to the relay connection.
 *
 * Opening the dialog makes the vendor dial its nostrconnect relays (and, for
 * a bunker link, the bunker's relay). The fixture's guard
 * (tests/e2e/support/election.js) takes every one of them, closes it and
 * records it; each spec allows exactly the relays it expects, with a reason,
 * and the warning the vendor logs when its nostrconnect subscription is
 * closed. Any other socket fails the test.
 *
 * Mutation seen red (j2b, 2026-11-16): the @include of the guard removed from
 * resources/views/layouts/app.blade.php turns the four nsec cases red (the
 * typed nsec stays in the field; the seeded and the linked nsec end up in
 * wnj:bunkerPointer); the bunker case stays green. Without the guard, the
 * vendor answered getPublicKey() and signEvent() for a #nostr-login=nsec1…
 * link at once, with the link's key and without any dialog.
 *
 * Mutations seen red (j2b2, 2026-11-16): the taint lifted (refused() judging
 * only `nsec1`) turns every key-by-key case red, the field holding the 58
 * characters after the prefix; with that field assertion taken out, the
 * vendor mirror and the fragment scan go red on their own. The panel's
 * stylesheet left out turns 1280x650, 1280x720 and 1366x768 red
 * ("field top -58 < 0", "warning top -14 < 0" at 1280x720); 390x844 stays
 * green, the vendor's full-width phone layout fits without the cap.
 */

const ELECTION = '/association/election'
const POINTER = 'wnj:bunkerPointer'
const WARNING = 'Kein nsec hier eintragen'
const NOSTRCONNECT_RELAYS = /^wss:\/\/(bucket\.coracle\.social|relay\.nsec\.app|nos\.lol|relay\.primal\.net)\/? \(refused by the e2e guard\)$/
const SUBSCRIPTION_CLOSED = /^warning: nostrconnect:\/\/ QR code handling failed: Error: subscription closed before connection was established\./

function throwawayNsec() {
    const sk = generateSecretKey()

    return { nsec: nsecEncode(sk), pubkey: getPublicKey(sk) }
}

/**
 * The vendor's default nostrconnect relays (window.nostr.js 0.7.0, no
 * `nostrConnectRelays` in window.wnjParams): dialled as soon as the dialog
 * shows, closed by the fixture, never on the network.
 */
function allowNostrconnect(channels) {
    channels.allow('websocket', NOSTRCONNECT_RELAYS, 'the vendor dialog dials its nostrconnect relays when it opens; the e2e guard closes them')
    channels.allow('console', SUBSCRIPTION_CLOSED, 'the vendor warns when the e2e guard closes its nostrconnect subscription')
}

/**
 * Every value the vendor's own input listener read from its connect field:
 * the listener does `T = this.value`, so this is the vendor's mirrored value,
 * keystroke by keystroke. Installed before the vendor script runs.
 */
async function watchVendorMirror(page) {
    await page.addInitScript(() => {
        const seen = []
        const wrapped = new WeakMap()
        const add = EventTarget.prototype.addEventListener
        const remove = EventTarget.prototype.removeEventListener
        window.__vendorMirror = seen

        EventTarget.prototype.addEventListener = function (type, listener, options) {
            if (type !== 'input' || !(this instanceof HTMLInputElement) || typeof listener !== 'function') {
                return add.call(this, type, listener, options)
            }
            const field = this
            const wrapper = function (...args) {
                if (field.placeholder.includes('bunker://')) {
                    seen.push(field.value)
                }

                return listener.apply(this, args)
            }
            wrapped.set(listener, wrapper)

            return add.call(this, type, wrapper, options)
        }
        EventTarget.prototype.removeEventListener = function (type, listener, options) {
            return remove.call(this, type, wrapped.get(listener) ?? listener, options)
        }
    })
}

const vendorMirror = (page) => page.evaluate(() => window.__vendorMirror)

/**
 * Every place a piece of the key could be left behind — localStorage,
 * sessionStorage, cookies, every field of the page and of open shadow roots,
 * and what the vendor mirrored — that holds six or more characters of the
 * key's bech32 payload in a row.
 */
function leakedFragments(page, nsec) {
    return page.evaluate((payload) => {
        const pieces = []
        for (let i = 0; i + 6 <= payload.length; i++) {
            pieces.push(payload.slice(i, i + 6))
        }
        const places = []
        for (const [name, store] of [['localStorage', localStorage], ['sessionStorage', sessionStorage]]) {
            for (let i = 0; i < store.length; i++) {
                const key = store.key(i)
                places.push([`${name}:${key}`, `${key}=${store.getItem(key)}`])
            }
        }
        places.push(['cookie', document.cookie])
        const roots = [document, ...Array.from(document.querySelectorAll('*')).filter((element) => element.shadowRoot).map((element) => element.shadowRoot)]
        for (const root of roots) {
            for (const field of root.querySelectorAll('input, textarea')) {
                places.push([`field:${field.name || field.placeholder || field.type}`, field.value])
            }
        }
        for (const value of window.__vendorMirror ?? []) {
            places.push(['vendor mirror', value])
        }

        return places.filter(([, text]) => pieces.some((piece) => text.toLowerCase().includes(piece))).map(([place]) => place)
    }, nsec.slice('nsec1'.length))
}

/**
 * The connect field and the warning against the viewport, and the warning
 * against the vendor's panel, which scrolls: anything outside is a finding.
 */
function offScreen(page) {
    return page.evaluate(() => {
        const host = Array.from(document.body.children).find((element) => element.shadowRoot?.getElementById('wnj'))
        const root = host.shadowRoot
        const field = root.querySelector('input[placeholder*="bunker://"]')
        const warning = root.querySelector('[data-portal-nsec-warning]')
        const panel = field.closest('.animate-show')
        const findings = []
        const check = (name, element, top, bottom) => {
            const box = element.getBoundingClientRect()
            if (box.top < top) {
                findings.push(`${name} top ${Math.round(box.top)} < ${Math.round(top)}`)
            }
            if (box.bottom > bottom) {
                findings.push(`${name} bottom ${Math.round(box.bottom)} > ${Math.round(bottom)}`)
            }
            if (box.left < 0 || box.right > window.innerWidth) {
                findings.push(`${name} x ${Math.round(box.left)}..${Math.round(box.right)} outside 0..${window.innerWidth}`)
            }
        }
        check('field', field, 0, window.innerHeight)
        if (!warning) {
            return [...findings, 'no warning']
        }
        check('warning', warning, 0, window.innerHeight)
        const visible = panel.getBoundingClientRect()
        check('warning in panel', warning, visible.top, visible.bottom)

        return findings
    })
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

// What the vendor may see of an nsec typed key by key: the letters before the
// `1` that marks it, never a character of the key itself.
const HARMLESS = ['', 'n', 'ns', 'nse', 'nsec']

for (const viewport of [{ width: 390, height: 844 }, { width: 1280, height: 650 }, { width: 1280, height: 720 }, { width: 1366, height: 768 }]) {
    test(`an nsec typed key by key leaves no fragment, and the field and the warning stay on screen (${viewport.width}x${viewport.height})`, async ({ page, channels }) => {
        allowNostrconnect(channels)
        await page.setViewportSize(viewport)
        scenario('member')
        await watchVendorMirror(page)
        const { nsec } = throwawayNsec()

        await page.goto(ELECTION)
        const field = await openVendorDialog(page)

        await field.pressSequentially(nsec)
        await expect(field).toHaveValue('')
        const warning = page.locator('[data-portal-nsec-warning]')
        await expect(warning).toBeVisible()
        await expect(warning).toContainText(WARNING)

        expect(await vendorMirror(page)).not.toHaveLength(0)
        expect((await vendorMirror(page)).filter((value) => !HARMLESS.includes(value))).toEqual([])
        expect(await leakedFragments(page, nsec)).toEqual([])

        await expect.poll(() => offScreen(page)).toEqual([])

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
        expect(await leakedFragments(page, nsec)).toEqual([])

        await page.reload()
        expect(await storedPointer(page)).toBeNull()
        expect(await leakedFragments(page, nsec)).toEqual([])
        expect(await page.evaluate(() => window.nostr?.isWnj === true)).toBe(true)
    })
}

test('a pasted nsec is refused in one go, and the vendor mirrors nothing of it', async ({ page, channels }) => {
    allowNostrconnect(channels)
    scenario('member')
    await watchVendorMirror(page)
    const { nsec } = throwawayNsec()

    await page.goto(ELECTION)
    const field = await openVendorDialog(page)

    await field.fill(nsec)
    await expect(field).toHaveValue('')
    await expect(page.locator('[data-portal-nsec-warning]')).toContainText(WARNING)
    expect((await vendorMirror(page)).filter((value) => value !== '')).toEqual([])
    expect(await leakedFragments(page, nsec)).toEqual([])
})

test('an nsec typed partly, deleted and typed again leaves no fragment', async ({ page, channels }) => {
    allowNostrconnect(channels)
    scenario('member')
    await watchVendorMirror(page)
    const { nsec } = throwawayNsec()

    await page.goto(ELECTION)
    const field = await openVendorDialog(page)

    // Stopped midway.
    await field.pressSequentially(nsec.slice(0, 20))
    await expect(field).toHaveValue('')
    expect(await leakedFragments(page, nsec)).toEqual([])

    // Deleted, as someone correcting a typo would, then typed on.
    for (let i = 0; i < 5; i++) {
        await field.press('Backspace')
    }
    await field.pressSequentially(nsec.slice(20))
    await expect(field).toHaveValue('')

    // And typed again from the start.
    await field.pressSequentially(nsec)
    await expect(field).toHaveValue('')

    expect((await vendorMirror(page)).filter((value) => !HARMLESS.includes(value))).toEqual([])
    expect(await leakedFragments(page, nsec)).toEqual([])
    expect(await storedPointer(page)).toBeNull()
})

test('after a typed nsec, a pasted nsec is refused and a pasted NIP-05 address passes; a reopened dialog takes a typed one', async ({ page, channels }) => {
    allowNostrconnect(channels)
    scenario('member')
    await watchVendorMirror(page)
    const { nsec } = throwawayNsec()

    await page.goto(ELECTION)
    let field = await openVendorDialog(page)

    await field.pressSequentially(nsec.slice(0, 30))
    await expect(field).toHaveValue('')

    await field.fill(nsec)
    await expect(field).toHaveValue('')

    await field.fill('alice@example.com')
    await expect(field).toHaveValue('alice@example.com')
    expect(await vendorMirror(page)).toContain('alice@example.com')

    // A reopened dialog has a new field, which starts clean.
    await field.evaluate((input) => { input.dataset.e2eOld = '' })
    const cancel = page.locator('[data-location="election"]').getByRole('button', { name: 'Abbrechen' })
    await cancel.click()
    await expect(cancel).toBeHidden()
    field = await openVendorDialog(page)
    await expect(field).not.toHaveAttribute('data-e2e-old')
    await field.fill('')
    await field.pressSequentially('bob@example.com')
    await expect(field).toHaveValue('bob@example.com')

    expect(await leakedFragments(page, nsec)).toEqual([])
    expect(await storedPointer(page)).toBeNull()
})

test('an nsec pointer already in storage is purged on load', async ({ page, channels }) => {
    allowNostrconnect(channels)
    scenario('member')
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

test('a #nostr-login=nsec1… link stores nothing and gives no signer', async ({ page, channels }) => {
    allowNostrconnect(channels)
    scenario('member')
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

for (const typedFirst of [false, true]) {
    test(`a bunker:// link still goes through to the bunker relay${typedFirst ? ', also after a typed nsec' : ''}`, async ({ page, channels }) => {
        allowNostrconnect(channels)
        scenario('member')
        const bunker = `bunker://${getPublicKey(generateSecretKey())}?relay=${encodeURIComponent('wss://bunker.relay.invalid')}`
        const toBunker = /^wss:\/\/bunker\.relay\.invalid\/? \(refused by the e2e guard\)$/

        await page.goto(ELECTION)
        const field = await openVendorDialog(page)

        if (typedFirst) {
            await field.pressSequentially(throwawayNsec().nsec)
            await expect(field).toHaveValue('')
        }

        await field.fill(bunker)
        await expect(field).toHaveValue(bunker)
        await field.press('Enter')

        // Seen by the fixture first, then allowed: the socket is the evidence.
        await expect.poll(() => channels.findings().some((finding) => finding.channel === 'websocket' && toBunker.test(finding.detail)), { timeout: 10000 }).toBe(true)
        channels.allow('websocket', toBunker, 'the bunker link names this relay; the vendor dials it on Enter and the e2e guard closes it')
        await expect(page.locator('[data-portal-nsec-warning]')).toHaveCount(typedFirst ? 1 : 0)
        expect(await storedPointer(page)).toBeNull()
    })
}
