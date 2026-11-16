import { test, expect, scenario, login } from './support/election.js'

/**
 * The DM centre in a real browser: compose, dry run, send, history, and the
 * repeat lock. The server's relay transport is the in-memory fake of
 * tests/e2e/support/e2e-server.php, which accepts every publish, so a sent
 * delivery here means the page and the jobs behind it went the whole way;
 * nothing reaches a relay. The queue is sync (scripts/e2e-election.sh).
 *
 * Asserted on data-test hooks and data-status, not on the sentences of the
 * page: their wording is being reworked in parallel (j1e).
 */

const TEXT = 'Hallo {name},\n\nder Vorstand lädt zur Mitgliederversammlung ein. Bis bald!'

function isLivewireUpdate(response) {
    return response.url().includes('/livewire') && response.request().method() === 'POST'
}

/**
 * Pick an option and, when that changes the value, wait for the roundtrip it
 * starts. Its answer re-renders the form; text typed before it arrives is
 * overwritten by the server's copy.
 */
async function choose(page, hook, value) {
    const select = page.locator(`select[data-test="${hook}"], [data-test="${hook}"] select`)

    if ((await select.inputValue()) === value) {
        return
    }

    await Promise.all([page.waitForResponse(isLivewireUpdate), select.selectOption(value)])
}

function textArea(page) {
    return page.locator('textarea[data-test="compose-text"], [data-test="compose-text"] textarea')
}

async function openCompose(page) {
    await page.locator('[data-test="tab-compose"]').click()
    await expect(page.locator('[data-test="tab-compose"]')).toHaveAttribute('aria-current', 'page')

    await choose(page, 'compose-type', 'individual')
    await choose(page, 'compose-audience', 'members')
}

async function dryRun(page) {
    await page.locator('[data-test="dry-run"]').click()

    return page.locator('[data-test="dry-run-result"]')
}

test('the board dry-runs, sends, sees the run in the history, and a repeat is skipped by the lock', async ({ page }) => {
    const data = scenario('dm')

    await login(page, data.board.secret, '/association/messages/admin')

    // History starts empty.
    await expect(page.locator('[data-test="runs-empty"]')).toBeVisible()

    // Dry run: counted, nobody skipped, the send button is offered.
    await openCompose(page)
    await textArea(page).fill(TEXT)
    // The text reaches the component debounced (400 ms). Wait until the
    // server has it, read off the rendered preview, before the dry run:
    // a dry run of the previous text would count a different message.
    await expect(page.locator('[data-test="previews"]')).toContainText('Mitgliederversammlung')

    const result = await dryRun(page)
    await expect(result).toBeVisible()
    await expect(result.locator('[data-test="skipped"]')).toHaveCount(0)
    // Everyone of the scenario is an active, paid member with an inbox:
    // the board member, three candidates, three voters.
    const count = 1 + Object.keys(data.candidates).length + Object.keys(data.voters).length
    await expect(result).toContainText(String(count))

    await page.locator('[data-test="open-confirm"]').click()
    await page.locator('[data-test="confirm-send"]').click()

    // The page switches to the history, with the new run open: every
    // recipient of the dry run has a delivery, and every one went out.
    await expect(page.locator('[data-test="tab-history"]')).toHaveAttribute('aria-current', 'page')
    const runs = page.locator('[data-test="runs"] li[data-test^="run-"]')
    await expect(runs).toHaveCount(1)
    const deliveries = page.locator('[data-test="run-detail"] [data-test^="delivery-"]')
    await expect(deliveries).toHaveCount(count)
    await expect(page.locator('[data-test="run-detail"] [data-test^="delivery-"][data-status="sent"]')).toHaveCount(count)

    // Same text, same audience, within the lock: the dry run skips them
    // all, and there is nothing to send.
    // The page keeps the text after sending; the repeat is the same draft.
    await openCompose(page)
    await expect(textArea(page)).toHaveValue(TEXT)
    const repeat = await dryRun(page)
    await expect(repeat.locator('[data-test="skipped"]')).toBeVisible()
    await expect(repeat.locator('[data-test="skipped"]')).toContainText(String(count))
    await expect(page.locator('[data-test="open-confirm"]')).toHaveCount(0)
    await expect(page.locator('[data-test="open-confirm-disabled"]')).toBeDisabled()

    // No second run was started.
    await page.locator('[data-test="tab-history"]').click()
    await expect(runs).toHaveCount(1)
})

/**
 * Board jury D1: at 390 px the native audience select cut „Alle Aktiv- und
 * Ehrenmitglieder“ to „…Ehrenmitgliede“ (248 px of text in a 240 px content
 * box). A native select clips its value without an ellipsis, so the board
 * would read a truncated audience. Every option is measured with the
 * select's own computed font, after the web font has loaded.
 */
test('every audience fits the native select at phone width', async ({ page }) => {
    const data = scenario('dm')

    // The login button sits in the desktop header; log in there, then load
    // the DM centre afresh at each phone width.
    await login(page, data.board.secret, '/association/messages/admin')

    for (const viewport of [{ width: 390, height: 844 }, { width: 360, height: 800 }]) {
        await page.setViewportSize(viewport)
        await page.reload()
        await page.locator('[data-test="tab-compose"]').click()
        await expect(page.locator('[data-test="tab-compose"]')).toHaveAttribute('aria-current', 'page')

        const select = page.locator('select[data-test="compose-audience"], [data-test="compose-audience"] select')
        await expect(select).toBeVisible()

        const measured = await select.evaluate(async (element) => {
            await document.fonts.ready
            const style = getComputedStyle(element)
            const px = (value) => parseFloat(value) || 0
            const contentWidth = element.getBoundingClientRect().width
                - px(style.paddingLeft) - px(style.paddingRight)
                - px(style.borderLeftWidth) - px(style.borderRightWidth)
            const context = document.createElement('canvas').getContext('2d')
            context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`

            return {
                font: context.font,
                fontLoaded: [...document.fonts].some((face) => face.family.replace(/"/g, '') === 'Inconsolata' && face.status === 'loaded'),
                contentWidth,
                options: [...element.options].map((option) => ({ text: option.text.trim(), width: context.measureText(option.text.trim()).width })),
            }
        })

        // Measured with the fallback font, the widths would say nothing.
        expect(measured.fontLoaded, measured.font).toBe(true)
        expect(measured.options.length).toBeGreaterThan(0)
        console.log(`compose-audience at ${viewport.width} px: content ${measured.contentWidth} px; ${measured.options.map((o) => `${o.text} = ${o.width} px`).join('; ')}`)

        // Inconsolata advances 8 px per character at 16 px; canvas reports
        // 8.0000153 (240.00046 for 30 characters). The 0.01 px allowance is that
        // rounding, not room: one more character is 8 px.
        for (const option of measured.options) {
            expect(option.width, `„${option.text}“ in a ${measured.contentWidth} px select at ${viewport.width} px`).toBeLessThanOrEqual(measured.contentWidth + 0.01)
        }
    }
})
