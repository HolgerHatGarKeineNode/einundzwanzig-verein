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

    // The confirm dialog opens on its first control, the safe one, as every
    // election dialog does: not on the page body behind it, and not on the
    // button that sends. Enter right after opening sends nothing.
    const cancel = page.locator('[data-test="cancel-send"]')
    await expect(cancel).toBeFocused()
    expect(await page.evaluate(() => document.activeElement?.closest('dialog') !== null)).toBe(true)
    await expect(page.locator('[data-test="confirm-send"]')).not.toBeFocused()

    // Reached by keyboard, the safe control shows the election pages' focus
    // ring: the message centre sits inside `.election-ui`, which draws it.
    await page.keyboard.press('Tab')
    await page.keyboard.press('Shift+Tab')
    await expect(cancel).toBeFocused()
    const ring = await cancel.evaluate((el) => {
        const style = getComputedStyle(el)
        return `${style.outlineStyle} ${style.outlineWidth} ${style.outlineColor}`
    })
    expect(ring, 'focus ring of the confirm dialog\'s cancel button').toBe('solid 2px rgb(247, 147, 26)')

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
