import { test, expect, scenario } from './support/election.js'

/**
 * Positive control for the fixture of the election and DM specs
 * (tests/e2e/support/election.js on top of channels.js), against the real
 * server of scripts/e2e-election.sh and the real election page.
 *
 * Those specs claim "no console error, no exception, no response >= 400, no
 * request off the machine". The claim is only worth something if the same
 * fixture, on the same server and page, has been seen catching each kind.
 * Each test provokes one, reads what was recorded, and takes it, so the
 * check after the test stays green only because it was claimed here.
 */

test.beforeEach(async ({ page }) => {
    scenario('member')
    await page.goto('/association/election')
    await expect(page.locator('[data-location="election"]')).toBeVisible()
})

async function findingsOn(channels, channel) {
    const ofChannel = () => channels.findings().filter((finding) => finding.channel === channel)

    await expect.poll(() => ofChannel().length, { message: `nothing arrived on [${channel}]` }).toBeGreaterThan(0)

    return ofChannel().map((finding) => finding.detail)
}

test('the election page by itself records nothing', async ({ channels }) => {
    expect(channels.findings()).toEqual([])
})

test('a thrown error on the election page is caught', async ({ page, channels }) => {
    await page.evaluate(() => {
        setTimeout(() => {
            throw new Error('positive control: thrown on the election page')
        })
    })

    expect(await findingsOn(channels, 'pageerror')).toEqual(['positive control: thrown on the election page'])
    expect(() => channels.assertSilent()).toThrow('[pageerror] positive control: thrown on the election page')
    channels.take()
})

test('a 404 from the e2e server is caught', async ({ page, channels, baseURL }) => {
    const status = await page.evaluate(async () => (await fetch('/association/election/no-such-page-j1d')).status)

    expect(status).toBe(404)
    expect((await findingsOn(channels, 'response')).some((detail) => detail.includes(`${baseURL}/association/election/no-such-page-j1d`))).toBe(true)
    expect(() => channels.assertSilent()).toThrow('/association/election/no-such-page-j1d')
    channels.take()
})

test('a request to another host is refused by the guard and caught', async ({ page, channels }) => {
    const outcome = await page.evaluate(async () => {
        try {
            await fetch('https://relay.e2e.invalid/probe', { mode: 'no-cors' })

            return 'reached'
        } catch {
            return 'refused'
        }
    })

    expect(outcome).toBe('refused')
    expect(await findingsOn(channels, 'foreign-host')).toEqual(expect.arrayContaining([expect.stringContaining('relay.e2e.invalid/probe')]))
    channels.take()
})

test('a WebSocket is refused by the guard and caught', async ({ page, channels }) => {
    const closed = await page.evaluate(() => new Promise((resolve) => {
        const socket = new WebSocket('wss://relay.e2e.invalid/')
        socket.onclose = () => resolve(true)
        socket.onerror = () => resolve(true)
    }))

    expect(closed).toBe(true)
    expect(await findingsOn(channels, 'websocket')).toEqual(expect.arrayContaining([expect.stringContaining('wss://relay.e2e.invalid/')]))
    channels.take()
})
