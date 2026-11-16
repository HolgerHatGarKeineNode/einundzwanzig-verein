import { test, expect, scenario, login } from './support/election.js'
import { keep, measure } from './support/measure.js'

/**
 * The board inbox (/association/messages/inbox) and the member's DM inbox
 * check on the profile page, in a real browser with every channel of
 * support/channels.js watched and the network guard on.
 *
 * The fixture (scenario "board-inbox") stores four messages: two from Ida,
 * who has an inbox relay, one long one from Bernd, who has none, and one
 * from someone without a portal account. The reply
 * goes through the sync queue to the in-memory relay of e2e-server.php.
 */

const WIDTHS = [390, 1280]

async function measured(page, name, path) {
    await page.evaluate(() => document.fonts.ready)

    const values = await measure(page)
    keep({ page: name, path, ...values })

    const at = `${name} at ${values.viewport.width}x${values.viewport.height}`
    expect.soft(values.body_scroll_width, `${at}: body.scrollWidth`).toBeLessThanOrEqual(values.viewport.width)
    expect.soft(values.tap_targets_under_44_detail, `${at}: tap targets under 44 px`).toEqual([])
}

test('the board reads a reply, the unread mark goes, and the answer is delivered', async ({ page }) => {
    const data = scenario('board-inbox')

    await login(page, data.board.secret, '/association/messages/inbox')

    const ida = page.locator(`[data-test="conversation-${data.ida.pubkey}"]`)
    await expect(ida).toBeVisible()
    await expect(page.locator(`[data-test="unread-${data.ida.pubkey}"]`)).toHaveText('2 neu')
    await expect(page.locator(`[data-test="conversation-${data.stranger}"] [data-test="not-member"]`)).toBeVisible()
    await expect(page.locator(`[data-test="conversation-${data.ida.pubkey}"] [data-test="not-member"]`)).toHaveCount(0)

    await ida.click()
    await expect(page.locator('[data-test="thread"] > li')).toHaveCount(2)
    await expect(page.locator(`[data-test="unread-${data.ida.pubkey}"]`)).toHaveCount(0)

    await page.locator('[data-test="reply"]').fill('Ja, der Link kommt mit der Einladung.')
    await page.locator('[data-test="send-reply"]').click()
    await expect(page.locator('[data-test="thread"] > li')).toHaveCount(3)
    await expect(page.locator('[data-test="thread"] > li').last()).toContainText('zugestellt')
    await expect(page.locator('[data-test="thread"] > li').last()).not.toContainText('nicht zugestellt')
})

test('the board inbox and the profile inbox check keep their limits at 390 and 1280 px', async ({ page }) => {
    const data = scenario('board-inbox')

    await login(page, data.board.secret, '/association/messages/inbox')

    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto('/association/messages/inbox')
        await expect(page.locator(`[data-test="conversation-${data.ida.pubkey}"]`)).toBeVisible()
        await measured(page, 'board inbox', '/association/messages/inbox')

        await page.goto(`/association/messages/inbox?kontakt=${data.bernd}`)
        await expect(page.locator('[data-test="thread"] > li')).toHaveCount(1)
        await expect(page.locator('[data-test="no-member-inbox"]')).toBeVisible()
        await measured(page, 'board inbox (thread open)', '/association/messages/inbox')

        // The profile page has older targets under 44 px (fax, e-mail, save, benefits
        // link, all from before this card); only the card's own are asserted here.
        await page.goto('/association/profile')
        const card = page.locator('[data-test="dm-inbox-check"]')
        await expect(card).toBeVisible()
        await page.evaluate(() => document.fonts.ready)
        const values = await measure(page)
        keep({ page: 'profile inbox check', path: '/association/profile', ...values })
        expect.soft(values.body_scroll_width, `profile at ${width}: body.scrollWidth`).toBeLessThanOrEqual(width)
        const cardBox = await card.boundingBox()
        const buttonBox = await page.locator('[data-test="dm-inbox-recheck"]').boundingBox()
        expect.soft(cardBox.x + cardBox.width, `profile at ${width}: card right edge`).toBeLessThanOrEqual(width)
        expect.soft(buttonBox.height, `profile at ${width}: re-check button height`).toBeGreaterThanOrEqual(44)
        if (process.env.E2E_SHOTS) {
            await card.screenshot({ path: `${process.env.E2E_SHOTS}/profile-card-${width}.png` })
            await page.goto(`/association/messages/inbox?kontakt=${data.bernd}`)
            await page.screenshot({ path: `${process.env.E2E_SHOTS}/board-inbox-${width}.png`, fullPage: true })
        }
    }
})

test('a click on a name opens the Nostr profile card inside the viewport, not the thread', async ({ page }) => {
    const data = scenario('board-inbox')

    await login(page, data.board.secret, '/association/messages/inbox')

    for (const width of WIDTHS) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto('/association/messages/inbox')

        const chip = page.locator(`[data-test="conversation-${data.ida.pubkey}"] [data-test="nostr-profile"]`)
        await chip.click()

        const card = page.locator('[data-test="nostr-profile-card"]').first()
        await expect(card).toBeVisible()
        await expect(card.locator('[data-test="card-links"] a').first()).toHaveAttribute('href', /^https:\/\/njump\.me\/npub1/)
        await expect(card.locator('[data-test="card-portal"]')).toBeVisible()
        await expect(page.locator('[data-test="thread"]')).toHaveCount(0)

        const box = await card.boundingBox()
        expect.soft(box.x, `card at ${width}: left edge`).toBeGreaterThanOrEqual(0)
        expect.soft(box.x + box.width, `card at ${width}: right edge`).toBeLessThanOrEqual(width)
        expect.soft(box.y + box.height, `card at ${width}: bottom edge`).toBeLessThanOrEqual(900)
        for (const link of await card.locator('a, button').all()) {
            const target = await link.boundingBox()
            expect.soft(target.height, `card at ${width}: tap target height`).toBeGreaterThanOrEqual(44)
        }
        await measured(page, 'board inbox (profile card open)', '/association/messages/inbox')
        if (process.env.E2E_SHOTS) {
            await page.screenshot({ path: `${process.env.E2E_SHOTS}/profile-card-inbox-${width}.png` })
        }

        await page.keyboard.press('Escape')
        await expect(card).toBeHidden()
    }
})
