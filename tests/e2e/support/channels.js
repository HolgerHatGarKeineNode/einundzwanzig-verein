import { test as base, expect } from '@playwright/test'

/**
 * One fixture for everything a server-side test cannot see of a page.
 *
 * Import `test` from this file instead of '@playwright/test' and every test
 * of the spec is watched on six channels, across all pages of its context:
 *
 *   console        console.error and console.warn
 *   pageerror      uncaught exceptions and unhandled promise rejections
 *   response       every response with a status of 400 or above
 *   requestfailed  requests that never got a response
 *   foreign-host   http(s) requests to a host other than the app's
 *   websocket      every WebSocket the browser opens (one that the spec
 *                  answers itself through routeWebSocket() never reaches
 *                  the browser's network stack and is not seen here)
 *
 * The channels are separate on purpose. A Livewire roundtrip answered with
 * 500 is a rejected promise the framework handles itself — it raises no
 * exception and may log nothing — and a thrown error never shows up as a
 * console message. Watching one of them and calling the page clean is how a
 * broken page passes.
 *
 * After each test the fixture fails it if anything was recorded. A spec that
 * expects a finding says so, with a reason:
 *
 *     channels.allow('websocket', /localhost:3341/, 'the chat island talks to the local relay')
 *
 * Recorded findings are filtered when they are checked, not when they arrive,
 * so allow() may be called at any point of the test.
 *
 * tests/e2e/channels-positive-control.spec.js injects one fault per channel
 * and one that is left for the check after the test. Without it, "nothing
 * recorded" could also mean "nothing was listening".
 */
export const test = base.extend({
    channels: [
        async ({ context, baseURL }, use) => {
            const appHost = new URL(baseURL).host
            const recorded = []
            const allowed = []

            const record = (channel, detail) => recorded.push({ channel, detail })

            const watchPage = (page) => {
                page.on('websocket', (socket) => record('websocket', socket.url()))
            }

            context.on('console', (message) => {
                if (message.type() === 'error' || message.type() === 'warning') {
                    record('console', `${message.type()}: ${message.text()}`)
                }
            })
            context.on('weberror', (webError) => record('pageerror', webError.error().message))
            context.on('response', (response) => {
                if (response.status() >= 400) {
                    record('response', `${response.status()} ${response.request().method()} ${response.url()}`)
                }
            })
            context.on('requestfailed', (request) => {
                record('requestfailed', `${request.method()} ${request.url()} ${request.failure()?.errorText ?? ''}`.trim())
            })
            context.on('request', (request) => {
                const url = new URL(request.url())

                if ((url.protocol === 'http:' || url.protocol === 'https:') && url.host !== appHost) {
                    record('foreign-host', `${request.method()} ${request.url()}`)
                }
            })
            context.on('page', watchPage)
            context.pages().forEach(watchPage)

            const isAllowed = (finding) =>
                allowed.some((rule) => rule.channel === finding.channel && rule.pattern.test(finding.detail))

            const channels = {
                /** Expect findings on one channel whose detail matches the pattern. */
                allow(channel, pattern, reason) {
                    if (typeof reason !== 'string' || reason.trim() === '') {
                        throw new Error('channels.allow() needs a reason: why is this finding expected here?')
                    }

                    allowed.push({ channel, pattern, reason })
                },

                /**
                 * Record a finding a spec saw itself, e.g. a WebSocket it answered
                 * through routeWebSocket(), which never reaches the 'websocket'
                 * channel above.
                 */
                record(channel, detail) {
                    record(channel, detail)
                },

                /** Everything recorded so far that no allow() covers. */
                findings() {
                    return recorded.filter((finding) => ! isAllowed(finding))
                },

                /** Like findings(), and forgets them: for a test that provoked them on purpose. */
                take() {
                    const findings = channels.findings()
                    recorded.length = 0

                    return findings
                },

                /** What runs after every test. Throws with one line per finding. */
                assertSilent() {
                    const findings = channels.findings()

                    if (findings.length > 0) {
                        throw new Error(
                            'The page was not silent:\n'
                            + findings.map((finding) => `  [${finding.channel}] ${finding.detail}`).join('\n'),
                        )
                    }
                },
            }

            await use(channels)

            channels.assertSilent()
        },
        { auto: true },
    ],
})

export { expect }
