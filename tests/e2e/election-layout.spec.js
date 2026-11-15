import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { test, expect, scenario, login } from './support/election.js'

/**
 * Layout of the election pages, measured in the real browser with the real
 * font, and written to a JSON file for the plan:
 *
 *   member page, admin-live, minutes (protocol), share view
 *   at 390, 820, 1280 and 1600 px wide; the share view also at 1280x720 and
 *   1920x1080, the sizes it is shared at in a call.
 *
 * Per page and viewport: body.scrollWidth against the viewport width, the
 * document's scrollHeight (for the share view against the viewport height),
 * and the tap targets smaller than 44 x 44 px.
 *
 * Limits, asserted (soft, so every value is measured and written first):
 *   - no page scrolls sideways: body.scrollWidth <= viewport width;
 *   - the share view fits its screen without scrolling at 1280x720 and
 *     1920x1080: scrollHeight <= viewport height;
 *   - no tap target of the page itself under 44 px in either direction.
 *     The page is Flux's main area ([data-flux-main]); a bare layout
 *     (minutes, share view) has no shell, so all of it is page. A link
 *     inside running text is exempt (WCAG 2.5.5 "inline") and counted
 *     apart. The app shell around it (sidebar,
 *     header) is the same on every page of the portal; its small targets
 *     are counted in the JSON and not asserted here.
 *
 * Output: $E2E_MEASURE_OUT, else test-results/messungen-j1d.json, written
 * after every measurement.
 */

const WIDTHS = [390, 820, 1280, 1600]
const HEIGHT = 900
const SHARE_SCREENS = [{ width: 1280, height: 720 }, { width: 1920, height: 1080 }]
const OUT = resolve(process.env.E2E_MEASURE_OUT || 'test-results/messungen-j1d.json')

/**
 * Kept on disk, not in memory: a failed test restarts the worker and would
 * take the numbers of the tests before it along. Entries of an earlier run
 * (another E2E_STATE_DIR) are dropped.
 */
function keep(entry) {
    const run = process.env.E2E_STATE_DIR ?? ''
    let file = null

    try {
        file = JSON.parse(readFileSync(OUT, 'utf8'))
    } catch {
        file = null
    }

    if (file === null || file.run !== run) {
        file = {
            run,
            measured_at: new Date().toISOString(),
            browser: 'host chromium (/bin/chromium), scripts/e2e-election.sh',
            limits: {
                scroll_width: 'body.scrollWidth <= viewport width',
                share_fit: 'share view scrollHeight <= viewport height at 1280x720 and 1920x1080',
                tap_targets: 'no tap target under 44 x 44 px inside the page area ([data-flux-main], or all of a bare layout); links inside running text exempt and counted apart; the app shell (sidebar, header) is counted, not asserted',
            },
            measurements: [],
        }
    }

    file.measurements.push(entry)
    mkdirSync(dirname(OUT), { recursive: true })
    writeFileSync(OUT, `${JSON.stringify(file, null, 2)}\n`)
}

/** The numbers of one page at the current viewport. */
function measure(page) {
    return page.evaluate(() => {
        const selector = 'a[href], button, input:not([type="hidden"]), select, textarea, summary, [role="button"], [role="tab"], [role="link"]'
        const small = []
        const inlineSmall = []
        const shellSmall = []

        const isInlineLink = (element) => {
            if (element.tagName !== 'A' || getComputedStyle(element).display !== 'inline') {
                return false
            }

            const text = (element.parentElement?.textContent ?? '').replace(element.textContent ?? '', '').trim()

            return text.length > 0
        }

        const describe = (element, box) => {
            const label = (element.getAttribute('aria-label') || element.textContent || element.getAttribute('name') || '').replace(/\s+/g, ' ').trim().slice(0, 40)
            const hook = element.closest('[data-test]')?.getAttribute('data-test') ?? ''

            return `${element.tagName.toLowerCase()}${hook ? `[${hook}]` : ''} "${label}" ${Math.round(box.width)}x${Math.round(box.height)}`
        }

        for (const element of document.querySelectorAll(selector)) {
            if (element.disabled || element.closest('[aria-hidden="true"], [inert]')) {
                continue
            }

            // A checkbox or radio is tapped through its label.
            const target = (element.type === 'checkbox' || element.type === 'radio') && element.closest('label')
                ? element.closest('label')
                : element
            const box = target.getBoundingClientRect()
            const style = getComputedStyle(target)

            // Not on screen at all: hidden, collapsed, the 1 px sr-only skip
            // link, or the closed mobile drawer translated off to the side.
            if (box.width <= 1 || box.height <= 1 || style.visibility === 'hidden' || style.display === 'none' || target.checkVisibility?.() === false
                || box.right <= 0 || box.left >= window.innerWidth) {
                continue
            }

            if (box.width < 44 || box.height < 44) {
                // The page is what sits in Flux's main area; a bare layout
                // (minutes, share view) has no shell, so all of it is page.
                const shell = document.querySelector('[data-flux-main]') !== null && ! target.closest('[data-flux-main]')

                if (shell) {
                    shellSmall.push(describe(target, box))
                } else {
                    (isInlineLink(target) ? inlineSmall : small).push(describe(target, box))
                }
            }
        }

        return {
            viewport: { width: window.innerWidth, height: window.innerHeight },
            body_scroll_width: document.body.scrollWidth,
            scroll_height: document.documentElement.scrollHeight,
            tap_targets_under_44: small.length,
            tap_targets_under_44_detail: [...new Set(small)].slice(0, 20),
            inline_links_under_44: inlineSmall.length,
            shell_tap_targets_under_44: shellSmall.length,
            shell_tap_targets_under_44_detail: [...new Set(shellSmall)].slice(0, 20),
        }
    })
}

/** Measure `path` at every viewport, assert the limits, keep the numbers. */
async function measurePage(page, name, path, viewports, { fit = false, ready } = {}) {
    for (const viewport of viewports) {
        await page.setViewportSize(viewport)
        await page.goto(path)
        await expect(page.locator(ready)).toBeVisible()
        await page.evaluate(() => document.fonts.ready)

        const values = await measure(page)
        keep({ page: name, path, ...values })

        const at = `${name} at ${viewport.width}x${viewport.height}`
        expect.soft(values.body_scroll_width, `${at}: body.scrollWidth`).toBeLessThanOrEqual(values.viewport.width)
        expect.soft(values.tap_targets_under_44_detail, `${at}: tap targets under 44 px`).toEqual([])

        if (fit) {
            expect.soft(values.scroll_height, `${at}: share view scrollHeight`).toBeLessThanOrEqual(values.viewport.height)
        }
    }
}

const atWidths = WIDTHS.map((width) => ({ width, height: HEIGHT }))

test('the member page keeps its limits at every width', async ({ page }) => {
    const data = scenario('measure')

    // Voter 2 voted in the counted round and not yet in the open one: the
    // page shows a result, a ballot and a waiting round at once.
    await login(page, data.voters.voter2.secret, '/association/election', '[data-location="election"] button')
    await measurePage(page, 'member page', '/association/election', atWidths, { ready: '[x-data^="electionBallot"]' })
})

test('admin-live, the minutes and the share view keep their limits', async ({ page }) => {
    const data = scenario('measure')
    const admin = '/association/election/admin'

    await login(page, data.board.secret, admin)

    // The live section is chosen by click, so each viewport opens it again.
    for (const viewport of atWidths) {
        await page.setViewportSize(viewport)
        await page.goto(admin)
        await page.locator('[data-test="tab-live"]').click()
        await expect(page.locator(`[data-test="live-round-${data.round}"]`)).toBeVisible()
        await page.evaluate(() => document.fonts.ready)

        const values = await measure(page)
        keep({ page: 'admin-live', path: `${admin} (Sitzung)`, ...values })

        const at = `admin-live at ${viewport.width}x${viewport.height}`
        expect.soft(values.body_scroll_width, `${at}: body.scrollWidth`).toBeLessThanOrEqual(values.viewport.width)
        expect.soft(values.tap_targets_under_44_detail, `${at}: tap targets under 44 px`).toEqual([])
    }

    await measurePage(page, 'minutes', `${admin}/${data.election}/protocol`, atWidths, { ready: '[data-test="protocol"]' })
    await measurePage(page, 'share view', `${admin}/${data.election}/screen`, atWidths, { ready: '[data-test="share-screen"]' })
    await measurePage(page, 'share view (call)', `${admin}/${data.election}/screen`, SHARE_SCREENS, { fit: true, ready: '[data-test="share-screen"]' })
})

/*
 * Positive control: the three measures above report zero on every page, so
 * each must be seen reporting a fault. A box wider than the screen, a small
 * button and a tall block are put on the share view; nothing is kept.
 */
test('the measures see a sideways scroll, a small tap target and an overflowing share view', async ({ page }) => {
    const data = scenario('measure')

    await login(page, data.board.secret, '/association/election/admin')
    await page.setViewportSize({ width: 1280, height: 720 })
    await page.goto(`/association/election/admin/${data.election}/screen`)
    await expect(page.locator('[data-test="share-screen"]')).toBeVisible()

    const clean = await measure(page)
    expect(clean.body_scroll_width).toBeLessThanOrEqual(clean.viewport.width)
    expect(clean.scroll_height).toBeLessThanOrEqual(clean.viewport.height)
    expect(clean.tap_targets_under_44).toBe(0)

    await page.evaluate(() => {
        const main = document.querySelector('[data-test="share-screen"] main')
        main.insertAdjacentHTML('beforeend', '<div style="width:2000px;height:600px">wide and tall</div><button type="button" style="width:30px;height:30px">x</button>')
    })

    const faulty = await measure(page)
    expect(faulty.body_scroll_width).toBeGreaterThan(faulty.viewport.width)
    expect(faulty.scroll_height).toBeGreaterThan(faulty.viewport.height)
    expect(faulty.tap_targets_under_44_detail).toEqual([expect.stringMatching(/^button\S* "x" 30x30$/)])
})
