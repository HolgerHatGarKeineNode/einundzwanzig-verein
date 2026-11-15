import { test, expect, scenario, castVotes, login } from './support/election.js'

/**
 * The share view is the window the board shares in the call. It is never
 * touched: it polls (wire:poll.3s) and must follow what the board does on
 * the admin page, a second tab of the same browser. Each step below changes
 * the state in the admin tab or the database and waits for the share view
 * to show it, without a reload.
 *
 * Asserted on the data-test hooks of the screen, not on its sentences.
 */

// One poll every 3 s; a state change must be visible after at most a few.
const FOLLOW = { timeout: 12_000 }

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
