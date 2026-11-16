import { test, expect, scenario, castVotes, closeRound, login } from './support/election.js'

/**
 * The share view is the window the board shares in the call. It is never
 * touched: it polls (wire:poll.1s.keep-alive) and must follow what the board does on
 * the admin page, a second tab of the same browser. Each step below changes
 * the state in the admin tab or the database and waits for the share view
 * to show it, without a reload.
 *
 * Asserted on the data-test hooks of the screen, not on its sentences.
 */

// One poll every second (keep-alive: also while the tab is in the
// background). The limit of 2 s from a close to the screen is pinned below;
// this wait only leaves room for a slow machine.
const FOLLOW = { timeout: 5_000 }

test('the share view follows open, turnout and the counted result without a reload', async ({ page, context }) => {
    const data = scenario('share')
    const { anna, bernd } = data.candidates

    await login(page, data.board.secret, '/association/election/admin')
    await page.locator('[data-test="tab-live"]').click()

    // The admin page opens the share view in a new tab.
    const [screen] = await Promise.all([
        context.waitForEvent('page'),
        page.locator('[data-test="open-screen"]').click(),
    ])
    await screen.waitForLoadState()
    expect(new URL(screen.url()).pathname).toBe(`/association/election/admin/${data.election}/screen`)

    const title = screen.locator('[data-test="share-round-title"]')
    await expect(title).toHaveText('Vorsitz')
    await expect(screen.locator('[data-test="share-turnout"]')).toHaveCount(0)
    await expect(screen.locator('[data-test="share-result"]')).toHaveCount(0)

    let navigations = 0
    screen.on('framenavigated', (frame) => {
        if (frame === screen.mainFrame()) {
            navigations++
        }
    })

    // Opened in the admin tab: the share view shows the turnout, at zero.
    await page.locator(`[data-test="open-round-${data.round}"]`).click()
    await page.locator('[data-test="confirm-round-action"]').click()
    await expect(page.locator(`[data-test="live-round-${data.round}"]`)).toHaveAttribute('data-state', 'open')

    await expect(screen.locator('[data-test="share-cast"]')).toHaveText('0', FOLLOW)

    // Votes come in: the count and the tally follow.
    castVotes(data.round, anna.id, 2)
    castVotes(data.round, bernd.id, 1)
    await expect(screen.locator('[data-test="share-cast"]')).toHaveText('3', FOLLOW)
    await expect(screen.locator('[data-test="share-tally"]')).toBeAttached()

    // Closed in the admin tab: the turnout gives way to the result.
    await page.locator(`[data-test="close-round-${data.round}"]`).click()
    await page.locator('[data-test="round-action-form"] textarea').fill('Alle haben abgestimmt')
    await page.locator('[data-test="confirm-round-action"]').click()

    const result = screen.locator('[data-test="share-result"]')
    await expect(result).toBeVisible(FOLLOW)
    await expect(screen.locator('[data-test="share-turnout"]')).toHaveCount(0)
    await expect(screen.locator('[data-test="share-outcome"]')).toContainText(anna.name)
    await expect(screen.locator('[data-test="share-row-name"]')).toHaveText([anna.name, bernd.name])
    await expect(title).toHaveText('Vorsitz')

    // All of it by polling: the share view never navigated.
    expect(navigations).toBe(0)
})

/*
 * Checklist Q19, measured on the screen as it is shared in a call: no text
 * under 28 px, and the densest result — five yes/no rows with a 63-character
 * name, decided by a chair at the 60-character limit (share-yesno-long) —
 * fits 1280×720 and 1920×1080 without scrolling either way.
 *
 * Mutations seen red (j2d, 2026-11-16): the 1.75rem floor of --s-meta back at
 * 1.5rem; the chair's decision back on a line of its own; the poll back at 3 s.
 */
for (const size of [{ width: 1280, height: 720 }, { width: 1920, height: 1080 }]) {
    test(`the densest result fits ${size.width}x${size.height} with no text under 28 px`, async ({ page }) => {
        const data = scenario('share-yesno-long')

        await login(page, data.board.secret, '/association/election/admin')
        await page.setViewportSize(size)
        await page.goto(`/association/election/admin/${data.election}/screen`)
        await expect(page.locator('[data-test="share-row"]')).toHaveCount(5)
        await expect(page.locator('[data-test="share-casting-vote"]')).toContainText(data.chair)
        await page.evaluate(() => document.fonts.ready)

        const measured = await page.evaluate(() => {
            const screen = document.querySelector('[data-test="share-screen"]')
            const walker = document.createTreeWalker(screen, NodeFilter.SHOW_TEXT)
            let smallest = Infinity
            let smallestText = ''

            while (walker.nextNode()) {
                const node = walker.currentNode
                const element = node.parentElement

                if (node.textContent.trim() === '' || ! element.checkVisibility()) {
                    continue
                }

                const size = parseFloat(getComputedStyle(element).fontSize)

                if (size < smallest) {
                    smallest = size
                    smallestText = node.textContent.trim().slice(0, 40)
                }
            }

            return {
                smallest,
                smallestText,
                scrollWidth: document.documentElement.scrollWidth,
                scrollHeight: Math.max(document.documentElement.scrollHeight, document.body.scrollHeight),
            }
        })

        const at = `share view at ${size.width}x${size.height}`
        expect.soft(measured.smallest, `${at}: smallest text ("${measured.smallestText}")`).toBeGreaterThanOrEqual(28)
        expect.soft(measured.scrollWidth, `${at}: scrollWidth`).toBeLessThanOrEqual(size.width)
        expect.soft(measured.scrollHeight, `${at}: scrollHeight`).toBeLessThanOrEqual(size.height)
    })
}

test('a closed round shows on the share view within 2 s', async ({ page }) => {
    const data = scenario('member')
    castVotes(data.round, data.candidates.anna.id, 2)

    await login(page, data.board.secret, '/association/election/admin')
    await page.setViewportSize({ width: 1280, height: 720 })
    await page.goto(`/association/election/admin/${data.election}/screen`)
    await expect(page.locator('[data-test="share-cast"]')).toHaveText('2')

    // Closed right after a poll came back, outside the browser, as the board
    // closes it in another tab: the worst case, a whole poll interval away
    // (about 1 s now; a 3 s poll misses the 2 s limit every time).
    await page.waitForResponse((response) => response.url().includes('/update') && response.request().method() === 'POST')
    closeRound(data.round)
    const closedAt = Date.now()
    await expect(page.locator('[data-test="share-result"]')).toBeVisible({ timeout: 5_000 })

    expect(Date.now() - closedAt, 'close to screen in ms').toBeLessThanOrEqual(2_000)
})
