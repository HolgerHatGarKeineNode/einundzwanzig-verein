import { readFileSync } from 'node:fs'
import { test, expect } from './support/channels.js'

/**
 * Where the focus goes on the ballot when signing cannot go ahead
 * (resources/js/electionBallot.js):
 *
 *   F1  No signer on this device (the window.nostr.js fallback, `isWnj`):
 *       „Signieren und abgeben“ shows the German help, and the focus moves
 *       onto it, as the login and the signer check do.
 *   F2  The signer never answers: after the 60 s timeout the focus is on
 *       „Signieren und abgeben“ again, not on BODY (the button was disabled
 *       while the signer was asked).
 *
 * Needs a server on a scratch database (tests/e2e/support/seed-ballot-member.php) with one member on the roll of an
 * open live round, and that member's session cookie as JSON in
 * E2E_BALLOT_SEED: {"cookie": "<name>", "value": "<encrypted id>"}.
 * Every non-local request and every WebSocket is cut off.
 */

const BASE = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8182'
const seed = JSON.parse(readFileSync(process.env.E2E_BALLOT_SEED, 'utf8'))

test.use({ baseURL: BASE, viewport: { width: 390, height: 844 } })

/** What has the focus: tag, id, data-signer-help, and its first line of text. */
async function focused(page) {
    return page.evaluate(() => {
        const element = document.activeElement

        return {
            tag: element?.tagName ?? null,
            id: element?.id ?? '',
            signerHelp: element?.hasAttribute('data-signer-help') ?? false,
            text: (element?.innerText ?? '').trim().split('\n')[0],
        }
    })
}

test.beforeEach(async ({ context, channels }) => {
    const host = new URL(BASE).host

    // The layout loads a font kit, avatars and a font from APP_URL; they are cut off on purpose.
    const blocked = /(https:\/\/(kit\.fontawesome\.com|image\.nostr\.build)\/|http:\/\/localhost:8000\/storage\/fonts\/)/
    channels.allow('foreign-host', blocked, 'non-local requests are blocked by this spec')
    channels.allow('requestfailed', blocked, 'non-local requests are blocked by this spec')
    channels.allow('console', /^error: Failed to load resource: net::ERR_BLOCKED_BY_CLIENT/, 'the browser reports each request this spec blocks')

    await context.route('**/*', (route) => (new URL(route.request().url()).host === host ? route.fallback() : route.abort('blockedbyclient')))
    await context.routeWebSocket(/.*/, (socket) => socket.close())
    await context.addCookies([{ name: seed.cookie, value: seed.value, url: BASE }])
})

/** The election page, ballot filled in blank and on the review step. */
async function openReview(page) {
    await page.goto('/association/election')
    await page.getByRole('button', { name: 'Prüfen' }).click()
    await expect(page.getByRole('heading', { name: 'Prüfe deine Stimme' })).toBeVisible()
}

test('F1: without a signer the focus moves onto the German help', async ({ page }) => {
    await openReview(page)
    expect(await page.evaluate(() => window.nostr?.isWnj === true)).toBe(true)

    await page.getByRole('button', { name: 'Signieren und abgeben' }).click()
    await expect(page.getByRole('heading', { name: 'Noch kein Nostr-Signer?' })).toBeVisible()

    await expect.poll(() => focused(page)).toMatchObject({ tag: 'SECTION', signerHelp: true })
})

test('F2: after the signer timeout the focus is on the sign button again', async ({ page }) => {
    // Installed before the page loads, so the 60 s of the signer timeout can be skipped.
    // A faked clock also holds Alpine's $nextTick (a setTimeout) until it is run on.
    await page.clock.install()
    await openReview(page)
    // A real signer that never answers; assigning drops the vendor's isWnj mark.
    await page.evaluate(() => {
        window.nostr = { getPublicKey: () => new Promise(() => {}), signEvent: () => new Promise(() => {}) }
    })

    await page.getByRole('button', { name: 'Signieren und abgeben' }).click()
    await expect(page.getByRole('list', { name: 'Wahlgänge' }).getByText('Warte auf deinen Signer …')).toBeVisible()
    expect((await focused(page)).tag).toBe('BODY')

    await page.clock.runFor(60_000)
    await page.clock.runFor(100)
    await expect(page.getByRole('list', { name: 'Wahlgänge' }).getByText('Dein Signer hat nach einer Minute nicht geantwortet.', { exact: false })).toBeVisible()

    await expect.poll(() => focused(page)).toMatchObject({ tag: 'BUTTON', text: 'Signieren und abgeben' })
})
