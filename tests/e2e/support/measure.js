import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'

/**
 * Layout measurement shared by tests/e2e/election-layout.spec.js and
 * tests/e2e/dm-inbox.spec.js: body.scrollWidth, scrollHeight and the tap
 * targets under 44 x 44 px of the page at its current viewport, and the file
 * the numbers are kept in.
 *
 * Output: $E2E_MEASURE_OUT, else test-results/messungen-j1d.json, written
 * after every measurement.
 */

const OUT = resolve(process.env.E2E_MEASURE_OUT || 'test-results/messungen-j1d.json')

/**
 * Kept on disk, not in memory: a failed test restarts the worker and would
 * take the numbers of the tests before it along. Entries of an earlier run
 * (another E2E_STATE_DIR) are dropped.
 */
export function keep(entry) {
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
export function measure(page) {
    return page.evaluate(() => {
        const selector = 'a[href], button, input:not([type="hidden"]), select, textarea, summary, [role="button"], [role="tab"], [role="link"], [role="checkbox"]'
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

            // A checkbox or radio is tapped through its label. A Flux checkbox
            // (ui-checkbox, role="checkbox") is tapped through its inline field,
            // box and label together; that every point of such a row really
            // toggles is pinned in tests/e2e/election-tie.spec.js.
            const fluxField = element.matches('[data-flux-checkbox]') ? element.closest('[data-flux-field]') : null
            const target = (element.type === 'checkbox' || element.type === 'radio') && element.closest('label')
                ? element.closest('label')
                : (fluxField?.querySelector(':scope > [data-flux-label]') ? fluxField : element)
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
