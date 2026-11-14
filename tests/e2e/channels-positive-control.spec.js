import { test, expect } from './support/channels.js'

/**
 * Positive control for tests/e2e/support/channels.js.
 *
 * Every spec that imports that fixture claims "the page raised nothing". The
 * claim is only worth something if each channel has been seen catching a
 * fault. So this spec injects one fault per channel, reads what the fixture
 * recorded, and — in the last test — leaves a fault unclaimed to see the
 * check after the test fail it.
 *
 * The spec needs no server and no database: every HTTP request is answered by
 * the route handler below, the foreign host included. The one thing that
 * really dials is the WebSocket, and it dials a closed port on 127.0.0.1.
 * Nothing leaves the machine.
 */

const PAGE = '<!doctype html><html><head><title>positive control</title><link rel="icon" href="data:,"></head><body></body></html>'

test.beforeEach(async ({ context, page }) => {
    await context.route('**/*', (route) => {
        const url = new URL(route.request().url())

        if (url.host === 'foreign.example') {
            return route.fulfill({ status: 200, contentType: 'text/plain', body: 'ok' })
        }
        if (url.pathname === '/') {
            return route.fulfill({ status: 200, contentType: 'text/html', body: PAGE })
        }
        if (url.pathname === '/missing') {
            return route.fulfill({ status: 404, contentType: 'text/plain', body: 'not found' })
        }
        if (url.pathname === '/livewire/update') {
            return route.fulfill({ status: 500, contentType: 'text/plain', body: 'server error' })
        }

        return route.abort('connectionrefused')
    })

    await page.goto('/')
})

/** The findings of one channel, once at least one has arrived. */
async function findingsOn(channels, channel) {
    const ofChannel = () => channels.findings().filter((finding) => finding.channel === channel)

    await expect.poll(() => ofChannel().length, { message: `nothing arrived on [${channel}]` }).toBeGreaterThan(0)

    return ofChannel().map((finding) => finding.detail)
}

test('a clean page records nothing', async ({ channels }) => {
    expect(channels.findings()).toEqual([])
})

test('a thrown error is caught on pageerror', async ({ page, channels }) => {
    await page.evaluate(() => {
        setTimeout(() => {
            throw new Error('positive control: thrown')
        })
    })

    expect(await findingsOn(channels, 'pageerror')).toEqual(['positive control: thrown'])
    expect(() => channels.assertSilent()).toThrow('[pageerror] positive control: thrown')
    channels.take()
})

test('an unhandled promise rejection is caught on pageerror', async ({ page, channels }) => {
    await page.evaluate(() => {
        Promise.reject(new Error('positive control: rejected'))
    })

    expect(await findingsOn(channels, 'pageerror')).toEqual(['positive control: rejected'])
    channels.take()
})

test('console.error is caught on console', async ({ page, channels }) => {
    await page.evaluate(() => console.error('positive control: console'))

    expect(await findingsOn(channels, 'console')).toEqual(['error: positive control: console'])
    channels.take()
})

test('a 404 is caught on response', async ({ page, channels, baseURL }) => {
    await page.evaluate(() => fetch('/missing'))

    expect(await findingsOn(channels, 'response')).toEqual([`404 GET ${baseURL}/missing`])
    channels.take()
})

/*
 * The fault no console assertion finds on its own: the page handles the
 * failed roundtrip itself and nothing is thrown.
 */
test('a Livewire roundtrip answered with 500 is caught on response', async ({ page, channels, baseURL }) => {
    await page.evaluate(() => fetch('/livewire/update', { method: 'POST', body: '{}' }).catch(() => {}))

    expect(await findingsOn(channels, 'response')).toEqual([`500 POST ${baseURL}/livewire/update`])
    expect(channels.findings().filter((finding) => finding.channel === 'pageerror')).toEqual([])
    channels.take()
})

test('a request that gets no response is caught on requestfailed', async ({ page, channels, baseURL }) => {
    await page.evaluate(() => fetch('/unreachable').catch(() => {}))

    expect(await findingsOn(channels, 'requestfailed')).toEqual([`GET ${baseURL}/unreachable net::ERR_CONNECTION_REFUSED`])
    channels.take()
})

test('a request to another host is caught on foreign-host', async ({ page, channels }) => {
    await page.evaluate(() => fetch('https://foreign.example/pixel', { mode: 'no-cors' }))

    expect(await findingsOn(channels, 'foreign-host')).toEqual(['GET https://foreign.example/pixel'])
    channels.take()
})

/*
 * Dials for real, to the discard port on loopback, where nothing listens. A
 * socket answered by context.routeWebSocket() would not do: Playwright serves
 * those inside the page and the browser never opens one — measured here, the
 * channel stayed empty. A spec that routes its WebSockets is therefore not
 * watched on this channel.
 */
test('an opened WebSocket is caught on websocket', async ({ page, channels }) => {
    await page.evaluate(() => {
        new WebSocket('ws://127.0.0.1:9/positive-control')
    })

    expect(await findingsOn(channels, 'websocket')).toEqual(['ws://127.0.0.1:9/positive-control'])
    channels.take()
})

test('allow() takes a matching finding out and demands a reason', async ({ page, channels }) => {
    expect(() => channels.allow('console', /expected/)).toThrow('needs a reason')

    channels.allow('console', /expected noise/, 'this test logs it on purpose')

    await page.evaluate(() => {
        console.error('expected noise')
        console.error('positive control: not covered')
    })

    expect(await findingsOn(channels, 'console')).toEqual(['error: positive control: not covered'])
    channels.take()
})

/*
 * The one test that is supposed to fail: it leaves a fault unclaimed, and
 * only the check the fixture runs after the test can turn that into a
 * failure. test.fail() makes Playwright report a failure here as a pass and a
 * pass as a failure — so if the check after the test ever stopped running,
 * this is where it would show.
 *
 * The body asserts nothing itself. A failing expectation in here would also
 * count as "failed as expected" and hide exactly what the test is for.
 */
test('a fault no test claimed fails the test afterwards', async ({ page }) => {
    test.fail()

    await page.evaluate(() => console.error('positive control: left for the check after the test'))
})
