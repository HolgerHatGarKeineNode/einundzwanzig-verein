import { execFileSync } from 'node:child_process'
import { finalizeEvent, getPublicKey } from 'nostr-tools/pure'
import { hexToBytes } from 'nostr-tools/utils'
import { test as watched, expect } from './channels.js'

/**
 * The fixture of the election and DM centre specs. Run them through
 * scripts/e2e-election.sh: it provides the server, the throwaway database and
 * the environment this file reads (E2E_STATE_DIR, DB_DATABASE, E2E_BASE_URL).
 *
 * On top of tests/e2e/support/channels.js (console, pageerror, responses
 * >= 400, failed requests, foreign hosts, WebSockets — any finding fails the
 * test), every test of a spec that imports `test` from here gets a network
 * guard:
 *
 *   - every http(s) request to another host than the app is aborted, so it
 *     never leaves the machine and is still reported as [foreign-host];
 *   - every WebSocket is answered by the spec and closed, and reported as
 *     [websocket]. The vendor window.nostr.js opens relay sockets as soon as
 *     its dialog shows; a page that does that is a finding;
 *   - two exceptions, both allowed by name:
 *     the cached font CSS names the dev server
 *     (http://localhost:8000/storage/fonts/…). Those files are fetched from
 *     this app's own /storage instead, with a CORS header, so the layout is
 *     measured with the real font and not a fallback.
 *     The app layout loads the Font Awesome kit from kit.fontawesome.com
 *     (resources/views/layouts/app.blade.php). It is answered with an empty
 *     script: the request is the app's, not the spec's, and a third-party
 *     script on the page that signs votes is reported separately (j1d).
 */

const FONT_ORIGIN = 'http://localhost:8000'

export const test = watched.extend({
    guard: [
        async ({ context, baseURL, channels }, use) => {
            const app = new URL(baseURL)

            channels.allow('foreign-host', /^GET http:\/\/localhost:8000\/storage\/fonts\//, 'the cached font CSS names the dev server; the guard serves the same files from this app')
            channels.allow('foreign-host', /^GET https:\/\/kit\.fontawesome\.com\//, 'the app layout loads the Font Awesome kit; the guard answers it with an empty script')

            await context.route(() => true, async (route) => {
                const url = new URL(route.request().url())

                if (url.host === app.host) {
                    return route.continue()
                }

                if (url.origin === FONT_ORIGIN && url.pathname.startsWith('/storage/fonts/')) {
                    const response = await route.fetch({ url: `${app.origin}${url.pathname}` })

                    return route.fulfill({ response, headers: { ...response.headers(), 'access-control-allow-origin': '*' } })
                }

                if (url.host === 'kit.fontawesome.com') {
                    return route.fulfill({ status: 200, contentType: 'text/javascript', body: '/* Font Awesome kit, not loaded in the e2e run */' })
                }

                return route.abort('blockedbyclient')
            })

            await context.routeWebSocket(() => true, (socket) => {
                channels.record('websocket', `${socket.url()} (refused by the e2e guard)`)
                socket.close()
            })

            await use(undefined)
        },
        { auto: true },
    ],
})

export { expect }

function php(args) {
    return execFileSync('php', ['tests/e2e/support/election-fixture.php', ...args], {
        env: process.env,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'inherit'],
    })
}

/** A fresh database with one scenario of tests/e2e/support/election-fixture.php. */
export function scenario(name) {
    return JSON.parse(php([name]))
}

/** Cast `count` votes for the candidate standing as member `memberId`, through CastBallot. */
export function castVotes(roundId, memberId, count = 1) {
    return JSON.parse(php(['cast', String(roundId), String(memberId), String(count)])).cast
}

/**
 * A NIP-07 browser extension that signs with a throwaway key and keeps a copy
 * of every event it was asked to sign, exactly as the page handed it over.
 *
 * The vendor window.nostr.js is answered with an empty script: a real
 * extension replaces it the same way, and its fallback object would
 * otherwise sit in front of this one.
 *
 * @returns {{ pubkey: string, signed: object[] }}
 */
export async function installSigner(page, secretHex) {
    const sk = hexToBytes(secretHex)
    const pubkey = getPublicKey(sk)
    const signed = []

    await page.route(/\/dist\/window\.nostr\.min\.js\.js/, (route) =>
        route.fulfill({ status: 200, contentType: 'text/javascript', body: '/* replaced by the e2e NIP-07 extension */' }),
    )
    await page.exposeFunction('__e2eGetPublicKey', () => pubkey)
    await page.exposeFunction('__e2eSignEvent', (event) => {
        signed.push(JSON.parse(JSON.stringify(event)))

        return finalizeEvent(event, sk)
    })
    await page.addInitScript(() => {
        window.nostr = {
            getPublicKey: () => window.__e2eGetPublicKey(),
            signEvent: (event) => window.__e2eSignEvent(event),
        }
    })

    return { pubkey, signed }
}

/**
 * Log in through the page's own login button and wait for the reload that
 * follows a successful login.
 *
 * @returns {Promise<{ pubkey: string, signed: object[] }>}
 */
export async function login(page, secretHex, path, buttonSelector = '[x-data="nostrLogin"] button') {
    const signer = await installSigner(page, secretHex)

    await page.goto(path)
    await page.locator(buttonSelector).filter({ visible: true }).first().click()
    await expect(page.locator('form[action$="/logout"]').first()).toBeAttached({ timeout: 15000 })

    return signer
}

/** The tags named `name` of a signed or unsigned event. */
export function tagsNamed(event, name) {
    return event.tags.filter((tag) => tag[0] === name)
}
