import { test, expect, scenario, login } from './support/election.js'

/**
 * The two tie dialogs of the board page and the share view of a decided tie,
 * in the real browser with the real font.
 *
 * Tap rows: every point of a checkbox row toggles it, the 18 px box column
 * included, in a short row and in a row wrapped by a 63-character name. A
 * point is clicked with the mouse at page coordinates, so a dead strip of the
 * row (a click that lands on the grid of ui-field instead of the box or the
 * label) shows as an unchanged data-checked.
 *
 * Share view: a 63-character elected name and a chair at the 60-character
 * limit (RecordCastingVote::MAX_CHAIR_LENGTH) fit the call screens 1280x720
 * and 1920x1080 without scrolling. The chair is entered through the dialog,
 * so the limit itself is pinned on the page as well.
 */

const ADMIN = '/association/election/admin'
const WIDTHS = [390, 1280]
const SHARE_SCREENS = [{ width: 1280, height: 720 }, { width: 1920, height: 1080 }]

async function openLive(page) {
    await page.locator('[data-test="tab-live"]').click()
    await expect(page.locator('[data-test="tab-live"]')).toHaveAttribute('aria-current', 'page')
}

/**
 * The points of a row to click: the box centre, 2 px above and below the box,
 * row top+3 and bottom-3 in the box column, the label middle and the right
 * end of the row.
 */
async function pointsOf(box, row, label) {
    const [b, r, l] = await Promise.all([box.boundingBox(), row.boundingBox(), label.boundingBox()])
    const cx = b.x + b.width / 2
    const cy = r.y + r.height / 2

    return {
        size: `box ${Math.round(b.width)}x${Math.round(b.height)}, row ${Math.round(r.width)}x${Math.round(r.height)}`,
        points: [
            ['box centre', cx, b.y + b.height / 2],
            ['2 px above the box', cx, b.y - 2],
            ['2 px below the box', cx, b.y + b.height + 2],
            ['row top+3, box column', cx, r.y + 3],
            ['row bottom-3, box column', cx, r.y + r.height - 3],
            ['label middle', l.x + l.width / 2, l.y + l.height / 2],
            ['right end of the row', r.x + r.width - 3, cy],
        ],
    }
}

/** Click every point of the row and expect each click to flip data-checked. */
async function expectEveryPointToggles(page, box, row, label, name) {
    const { size, points } = await pointsOf(box, row, label)
    test.info().annotations.push({ type: 'row', description: `${name}: ${size}` })

    for (const [where, x, y] of points) {
        const before = await box.evaluate((element) => element.hasAttribute('data-checked'))
        const hit = await page.evaluate(([px, py]) => {
            const element = document.elementFromPoint(px, py)

            return element ? `${element.tagName.toLowerCase()}${element.dataset.test ? `[${element.dataset.test}]` : ''}` : 'none'
        }, [x, y])

        await page.mouse.click(x, y)

        await expect
            .poll(() => box.evaluate((element) => element.hasAttribute('data-checked')), { message: `${name} (${size}): click at ${where} (${Math.round(x)},${Math.round(y)}) hits ${hit} and must flip data-checked`, timeout: 2000 })
            .toBe(! before)
    }
}

for (const width of WIDTHS) {
    test(`every point of a casting-vote row toggles it at ${width} px`, async ({ page }) => {
        const data = scenario('tie')

        // The login button sits in the closed drawer below 1024 px: log in wide, then narrow.
        await login(page, data.board.secret, ADMIN)
        await page.setViewportSize({ width, height: 900 })
        await page.goto(ADMIN)
        await openLive(page)
        await page.locator(`[data-test="casting-vote-${data.round}"]`).click()

        const dialog = page.locator('[data-test="casting-vote-form"]')
        await expect(dialog).toBeVisible()
        await page.evaluate(() => document.fonts.ready)

        const rows = dialog.locator('li')
        await expect(rows).toHaveCount(2)

        for (const index of [0, 1]) {
            const row = rows.nth(index)
            const label = row.locator('[data-test^="casting-candidate-"]')
            await expectEveryPointToggles(page, row.locator('[data-flux-checkbox]'), row, label, `casting row "${(await label.innerText()).split('\n')[0]}"`)
        }
    })

    test(`every point of the run-off row toggles it at ${width} px`, async ({ page }) => {
        const data = scenario('tie')

        // The login button sits in the closed drawer below 1024 px: log in wide, then narrow.
        await login(page, data.board.secret, ADMIN)
        await page.setViewportSize({ width, height: 900 })
        await page.goto(ADMIN)
        await openLive(page)
        await page.locator(`[data-test="tie-run-off-${data.round}"]`).click()

        const dialog = page.locator('[data-test="tie-run-off-form"]')
        await expect(dialog).toBeVisible()
        await page.evaluate(() => document.fonts.ready)

        const row = dialog.locator('[data-test="run-off-row"]')
        await expectEveryPointToggles(page, row.locator('[data-flux-checkbox]'), row, row.locator('[data-test="run-off-resolved-label"]'), 'run-off row')
    })
}

test('a 63-character elected name and a 60-character chair fit the share view at 1280x720 and 1920x1080', async ({ page, context }) => {
    const data = scenario('tie')

    await login(page, data.board.secret, ADMIN)
    await openLive(page)
    await page.locator(`[data-test="casting-vote-${data.round}"]`).click()

    const dialog = page.locator('[data-test="casting-vote-form"]')
    await expect(dialog).toBeVisible()
    await dialog.locator('[data-test^="casting-candidate-"]').filter({ hasText: data.long.name }).click()

    // One character over the limit, past the field's maxlength as a tampered
    // request would send it: the server refuses it with the German message.
    const chair = dialog.locator('[data-test="casting-chair"]')
    await expect(chair).toHaveAttribute('maxlength', '60')
    await chair.evaluate((input) => input.removeAttribute('maxlength'))
    await chair.fill(`${data.chair}x`)
    await dialog.locator('[data-test="confirm-casting-vote"]').click()
    await expect(dialog).toContainText('Der Name darf höchstens 60 Zeichen lang sein.')

    await chair.fill(data.chair)
    await dialog.locator('[data-test="confirm-casting-vote"]').click()
    await expect(dialog).toBeHidden()

    const [screen] = await Promise.all([
        context.waitForEvent('page'),
        page.locator('[data-test="open-screen"]').click(),
    ])
    await screen.waitForLoadState()

    for (const viewport of SHARE_SCREENS) {
        await screen.setViewportSize(viewport)
        await screen.reload()
        await expect(screen.locator('[data-test="share-casting-vote"]')).toHaveText(`Entschieden durch Stichentscheid der/des Vorsitzenden ${data.chair}.`)
        await expect(screen.locator('[data-test="share-outcome"]')).toContainText(data.long.name)
        await screen.evaluate(() => document.fonts.ready)

        const height = await screen.evaluate(() => document.documentElement.scrollHeight)
        expect.soft(height, `share view at ${viewport.width}x${viewport.height}: scrollHeight`).toBe(viewport.height)
    }
})

for (const viewport of [{ width: 390, height: 844 }, { width: 1440, height: 900 }]) {
    test(`the casting dialog refuses a person standing in another opened round, in words, at ${viewport.width} px`, async ({ page }) => {
        const data = scenario('tie-opened-elsewhere')

        // The login button sits in the closed drawer below 1024 px: log in wide, then narrow.
        await login(page, data.board.secret, ADMIN)
        await page.setViewportSize(viewport)
        await page.goto(ADMIN)
        await openLive(page)
        await page.locator(`[data-test="casting-vote-${data.round}"]`).click()

        const dialog = page.locator('[data-test="casting-vote-form"]')
        await expect(dialog).toBeVisible()
        await dialog.locator('[data-test^="casting-candidate-"]').filter({ hasText: data.long.name }).click()
        await dialog.locator('[data-test="casting-chair"]').fill('Erika Vorsitz')
        await dialog.locator('[data-test="confirm-casting-vote"]').click()

        const text = `${data.long.name} kandidiert auch im Wahlgang „Kassenprüfung und Revision“, der noch nicht ausgezählt ist. Entscheidet für eine andere Person aus dem Gleichstand, oder zählt „Kassenprüfung und Revision“ zuerst aus.`
        const error = dialog.getByText(text)
        await expect(error).toBeVisible()
        await page.evaluate(() => document.fonts.ready)
        await page.evaluate(() => Promise.all(document.getAnimations().filter((a) => Number.isFinite(a.effect?.getComputedTiming().endTime)).map((a) => a.finished)))

        const values = await error.evaluate((message) => {
            const box = (element) => {
                const r = element.getBoundingClientRect()

                return { x: Math.round(r.x), y: Math.round(r.y), width: Math.round(r.width), height: Math.round(r.height), right: Math.round(r.right) }
            }
            const form = document.querySelector('[data-test="casting-vote-form"]')

            return {
                viewport: { width: window.innerWidth, height: window.innerHeight },
                document_scroll_width: document.documentElement.scrollWidth,
                body_scroll_width: document.body.scrollWidth,
                form: box(form),
                form_scroll_width: form.scrollWidth,
                form_client_width: form.clientWidth,
                message: { ...box(message), scroll_width: message.scrollWidth, client_width: message.clientWidth, color: getComputedStyle(message).color, inside_form: form.contains(message) },
            }
        })
        test.info().annotations.push({ type: 'measure', description: JSON.stringify(values) })
        console.log(`MEASURE ${viewport.width}x${viewport.height} ${JSON.stringify(values)}`)

        expect(values.message.inside_form, 'the refusal stands in the casting dialog').toBe(true)
        expect.soft(values.document_scroll_width, 'documentElement.scrollWidth').toBeLessThanOrEqual(values.viewport.width)
        expect.soft(values.body_scroll_width, 'body.scrollWidth').toBeLessThanOrEqual(values.viewport.width)
        expect.soft(values.form_scroll_width, 'form scrollWidth').toBeLessThanOrEqual(values.form_client_width)
        expect.soft(values.message.scroll_width, 'message scrollWidth').toBeLessThanOrEqual(values.message.client_width)
        expect.soft(values.message.right, 'message right edge').toBeLessThanOrEqual(values.viewport.width)

        if (process.env.J2I_SHOT_DIR) {
            await page.screenshot({ path: `${process.env.J2I_SHOT_DIR}/casting-refusal-opened-elsewhere-${viewport.width}x${viewport.height}.png` })
        }
    })
}
