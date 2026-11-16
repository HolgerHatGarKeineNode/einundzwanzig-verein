import { rmSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { finalizeEvent, getPublicKey } from 'nostr-tools/pure'
import { hexToBytes } from 'nostr-tools/utils'
import { test, expect, scenario, installSigner, tagsNamed } from './support/election.js'

/**
 * A guest tab left open longer than the login challenge lives (300 s,
 * NostrAuth::CHALLENGE_TTL_SECONDS), then "Mit Nostr anmelden".
 *
 * Jury run 2, H-1: nostrLogin.js signed the challenge rendered into the page.
 * The server refused it inside the Livewire listener with HTTP 200, nothing
 * told the browser, the overlay stayed up without a message and a retry
 * failed the same way. Only a reload helped. On election day that is the
 * member who opened the page before the call.
 *
 * Pinned here:
 *   - after the idle time the first click logs in, with a challenge fetched
 *     at click time (not the rendered one);
 *   - a refusal by the server closes the overlay and shows a German toast,
 *     and the next click logs in without a reload;
 *   - a login request that never gets an answer ends the wait as well.
 *
 * The idle time is simulated, not waited for: the server runs ahead through
 * $E2E_STATE_DIR/clock-offset (tests/e2e/support/e2e-server.php) and the page
 * clock moves by the same amount, so the event's created_at stays inside the
 * server's ±300 s window, exactly as on a tab that really sat idle.
 *
 * Mutations seen red (j2b, 2026-11-16, each with `npm run build`):
 *   - nostrLogin.js reverted to d4e539b: all three cases red;
 *   - resolveChallenge() reading data-nostr-challenge first again: the first
 *     case red (not logged in), the second red at the retry;
 *   - nostrLoginRefused() returning at once: the second case red (no toast).
 */

const ELECTION = '/association/election'
const IDLE_SECONDS = 400
const OFFSET_FILE = join(process.env.E2E_STATE_DIR ?? '/nonexistent', 'clock-offset')

test.afterEach(() => rmSync(OFFSET_FILE, { force: true }))

/** Move server and page clock forward together, as if the tab sat idle. */
async function idle(page, seconds) {
    writeFileSync(OFFSET_FILE, String(seconds))
    await page.clock.setSystemTime(new Date(Date.now() + seconds * 1000))
}

function cta(page) {
    return page.locator('[data-location="election"]').getByRole('button', { name: 'Mit Nostr anmelden' }).filter({ visible: true })
}

function overlay(page) {
    return page.locator('[data-location="election"] [role="dialog"]')
}

const loggedIn = (page) => page.locator('form[action$="/logout"]').first()

test('a guest tab older than the challenge TTL logs in on the first click', async ({ page }) => {
    const data = scenario('member')
    const signer = await installSigner(page, data.voters.voter1.secret)

    await page.clock.install()
    await page.goto(ELECTION)
    await expect(cta(page)).toBeVisible()
    const rendered = await page.locator('[data-location="election"]').getAttribute('data-nostr-challenge')
    expect(rendered).toMatch(/^[0-9a-f]{64}$/)

    await idle(page, IDLE_SECONDS)
    await cta(page).click()

    await expect(loggedIn(page)).toBeAttached({ timeout: 15000 })

    const logins = signer.signed.filter((event) => event.kind === 22242)
    expect(logins, 'exactly one login event: the first click worked').toHaveLength(1)
    const [[, challenge]] = tagsNamed(logins[0], 'challenge')
    expect(challenge).toMatch(/^[0-9a-f]{64}$/)
    expect(challenge, 'the login signed the expired challenge rendered into the page').not.toBe(rendered)
})

test('a login the server refuses closes the overlay, says what to do, and the next click logs in', async ({ page }) => {
    const data = scenario('member')
    const sk = hexToBytes(data.voters.voter1.secret)
    const signed = []

    await page.route(/\/dist\/window\.nostr\.min\.js\.js/, (route) =>
        route.fulfill({ status: 200, contentType: 'text/javascript', body: '/* replaced by the e2e NIP-07 extension */' }),
    )
    // First answer: the NIP-42 relay AUTH form (a `relay` tag), which the
    // server must refuse as a login. Afterwards the event as handed over.
    await page.exposeFunction('__e2eGetPublicKey', () => getPublicKey(sk))
    await page.exposeFunction('__e2eSignEvent', (event) => {
        const tags = signed.length === 0 ? [...event.tags, ['relay', 'ws://127.0.0.1:9']] : event.tags
        signed.push({ ...event, tags })

        return finalizeEvent({ ...event, tags }, sk)
    })
    await page.addInitScript(() => {
        window.nostr = {
            getPublicKey: () => window.__e2eGetPublicKey(),
            signEvent: (event) => window.__e2eSignEvent(event),
        }
    })

    await page.clock.install()
    await page.goto(ELECTION)
    await expect(cta(page)).toBeVisible()
    await idle(page, IDLE_SECONDS)

    await cta(page).click()

    await expect(page.getByText('Der Server hat die Anmeldung nicht angenommen', { exact: false }).first()).toBeVisible({ timeout: 15000 })
    await expect(overlay(page)).toBeHidden()
    await expect(cta(page)).toBeEnabled()
    await expect(cta(page)).toBeFocused()
    await expect(page.locator('form[action$="/logout"]')).toHaveCount(0)
    expect(signed).toHaveLength(1)

    // No reload: the same tab logs in on the next click.
    await cta(page).click()
    await expect(loggedIn(page)).toBeAttached({ timeout: 15000 })
    expect(signed).toHaveLength(2)
    expect(tagsNamed(signed[1], 'relay')).toEqual([])
})

test('a login request that never gets an answer ends the wait with a German toast', async ({ page, channels }) => {
    channels.allow('requestfailed', /^POST http:\/\/127\.0\.0\.1:\d+\/livewire-[0-9a-f]+\/update net::ERR_FAILED$/, 'the spec drops the login roundtrip to simulate a dead connection')
    channels.allow('console', /^error: Failed to load resource: net::ERR_FAILED$/, 'the browser reports the dropped login roundtrip itself')

    const data = scenario('member')
    await installSigner(page, data.voters.voter1.secret)
    let dropped
    const loginDropped = new Promise((resolve) => { dropped = resolve })
    await page.route(/\/livewire-[0-9a-f]+\/update$/, async (route) => {
        if (!(route.request().postData() ?? '').includes('nostrLoggedIn')) {
            return route.fallback()
        }
        await route.abort('failed')
        dropped()
    })

    await page.clock.install()
    await page.goto(ELECTION)
    await expect(cta(page)).toBeVisible()

    await cta(page).click()
    await loginDropped
    await page.clock.runFor(29_000)
    await expect(overlay(page), 'the wait ended before LOGIN_SERVER_TIMEOUT_MS').toBeVisible()
    await page.clock.runFor(2_000)

    await expect(page.getByText('Der Server hat nicht geantwortet', { exact: false }).first()).toBeVisible()
    await expect(overlay(page)).toBeHidden()
    await expect(page.locator('form[action$="/logout"]')).toHaveCount(0)
})
