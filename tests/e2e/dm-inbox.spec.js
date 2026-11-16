import { test, expect, scenario, login } from './support/election.js'
import { keep, measure } from './support/measure.js'

/**
 * The member's inbox („Nachrichten vom Verein“, /association/messages) in a
 * real browser.
 *
 * The fixture (scenario "inbox") sends the member two messages through the
 * application's own path — ComposeDirectMessage, a frozen run,
 * DispatchBulkDirectMessages with the in-memory relay — one while the member
 * has an inbox relay (sent), one after it was removed (no_inbox, "only
 * here"). Every channel of tests/e2e/support/channels.js is watched, and the
 * network guard of support/election.js is on.
 *
 * Asserted on the texts the fixture sent, on aria-expanded and on the nav
 * badge, not on the page's own sentences: the DM views are reworded in
 * parallel (j2e).
 *
 * Layout: the inbox with every message closed and with one open, at 390 and
 * 1280 px, kept in the measurement file with the election pages
 * (support/measure.js) and asserted with the same limits.
 */

const WIDTHS = [390, 1280]

function rows(page) {
    return page.locator('ul[aria-label] > li[wire\\:key^="message-"]')
}

function badge(page) {
    return page.locator('[data-inbox-link] [data-flux-navlist-badge]').first()
}

async function measureInbox(page, name) {
    await page.evaluate(() => document.fonts.ready)

    const values = await measure(page)
    keep({ page: name, path: '/association/messages', ...values })

    const at = `${name} at ${values.viewport.width}x${values.viewport.height}`
    expect.soft(values.body_scroll_width, `${at}: body.scrollWidth`).toBeLessThanOrEqual(values.viewport.width)
    expect.soft(values.tap_targets_under_44_detail, `${at}: tap targets under 44 px`).toEqual([])
}

test('a member reads the messages of the association, newest first, and the unread count follows', async ({ page }) => {
    const data = scenario('inbox')

    await login(page, data.viewer.secret, '/association/messages')

    // Newest first: the "only here" message, then the one Nostr carried too.
    await expect(rows(page)).toHaveCount(2)
    await expect(rows(page).nth(0)).toContainText(data.messages.noInbox.marker)
    await expect(rows(page).nth(1)).toContainText(data.messages.sent.marker)
    await expect(badge(page)).toHaveText('2')

    // Opening one shows its whole text, as sent to this member, and marks it seen.
    const newest = rows(page).nth(0).getByRole('button')
    await expect(newest).toHaveAttribute('aria-expanded', 'false')
    await newest.click()
    await expect(newest).toHaveAttribute('aria-expanded', 'true')
    const opened = page.locator(`#${await newest.getAttribute('aria-controls')}`)
    await expect(opened).toBeVisible()
    await expect(opened).toContainText(`Hallo ${data.viewer.name},`)
    await expect(opened).toContainText(data.messages.noInbox.marker)
    await expect(opened.locator('a[href="https://verein.example/beitrag"]')).toBeVisible()
    await expect(badge(page)).toHaveText('1')

    // Seen is stored: after a reload the count stays at one.
    await page.reload()
    await expect(rows(page)).toHaveCount(2)
    await expect(badge(page)).toHaveText('1')

    // A second click closes it again.
    await rows(page).nth(0).getByRole('button').click()
    await expect(rows(page).nth(0).getByRole('button')).toHaveAttribute('aria-expanded', 'true')
    await rows(page).nth(0).getByRole('button').click()
    await expect(rows(page).nth(0).getByRole('button')).toHaveAttribute('aria-expanded', 'false')
})

test('the inbox keeps its limits at 390 and 1280 px, closed and with a message open', async ({ page }) => {
    const data = scenario('inbox')

    await login(page, data.viewer.secret, '/association/messages')

    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto('/association/messages')
        await expect(rows(page)).toHaveCount(2)
        await measureInbox(page, 'inbox')

        const newest = rows(page).nth(0).getByRole('button')
        await newest.click()
        await expect(newest).toHaveAttribute('aria-expanded', 'true')
        await expect(page.locator(`#${await newest.getAttribute('aria-controls')}`)).toBeVisible()
        await measureInbox(page, 'inbox (message open)')
    }
})
