import { test, expect, scenario, login } from './support/election.js'
import { keep, measure } from './support/measure.js'

/**
 * Layout of the election pages, measured in the real browser with the real
 * font, and written to a JSON file for the plan:
 *
 *   member page, admin-live, minutes (protocol), share view, and the two
 *   tie dialogs of admin-live (casting vote, run-off)
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

test('the tie dialogs keep their limits at every width', async ({ page }) => {
    const data = scenario('tie')
    const admin = '/association/election/admin'

    await login(page, data.board.secret, admin)

    for (const viewport of atWidths) {
        await page.setViewportSize(viewport)
        await page.goto(admin)
        await page.locator('[data-test="tab-live"]').click()

        for (const [name, button, form] of [['casting-vote dialog', 'casting-vote', 'casting-vote-form'], ['run-off dialog', 'tie-run-off', 'tie-run-off-form']]) {
            await page.locator(`[data-test="${button}-${data.round}"]`).click()
            await expect(page.locator(`[data-test="${form}"]`)).toBeVisible()
            // The modal scales in: measured before its transition ends, every box is a few px short.
            await page.evaluate(() => Promise.all([document.fonts.ready, ...document.getAnimations().filter((animation) => animation.effect?.getComputedTiming().endTime !== Infinity).map((animation) => animation.finished)]))

            const values = await measure(page)
            keep({ page: name, path: `${admin} (Sitzung, ${name})`, ...values })

            const at = `${name} at ${viewport.width}x${viewport.height}`
            expect.soft(values.body_scroll_width, `${at}: body.scrollWidth`).toBeLessThanOrEqual(values.viewport.width)
            expect.soft(values.tap_targets_under_44_detail, `${at}: tap targets under 44 px`).toEqual([])

            await page.keyboard.press('Escape')
            await expect(page.locator(`[data-test="${form}"]`)).toBeHidden()
        }
    }
})

/*
 * Positive control: the three measures above report zero on every page, so
 * each must be seen reporting a fault. A box wider than the screen, a small
 * button, an 18 px checkbox role (a Flux checkbox outside a labelled row) and
 * a tall block are put on the share view; nothing is kept.
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
        main.insertAdjacentHTML('beforeend', '<div style="width:2000px;height:600px">wide and tall</div><button type="button" style="width:30px;height:30px">x</button><span role="checkbox" tabindex="0" aria-label="box" style="display:inline-block;width:18px;height:18px"></span>')
    })

    const faulty = await measure(page)
    expect(faulty.body_scroll_width).toBeGreaterThan(faulty.viewport.width)
    expect(faulty.scroll_height).toBeGreaterThan(faulty.viewport.height)
    expect(faulty.tap_targets_under_44_detail).toEqual([expect.stringMatching(/^button\S* "x" 30x30$/), expect.stringMatching(/^span\S* "box" 18x18$/)])
})

/*
 * The member page's own promises (j2d), each asserted on the rendered page:
 *
 *   Q9   every focusable Flux button, Flux checkbox, Flux text field and link
 *        of an election page shows a 2px solid brand-orange (#F7931A) outline
 *        on keyboard focus (resources/css/components/flux-overrides.css,
 *        scoped by .election-ui). The ballot's own radios sit on the light
 *        paper sheet and keep their 3px ink ring: not asserted here.
 *   Q11  a candidate without a profile name is shown by the whole npub, never
 *        cut or ended in „…“, and it wraps inside its box at 320 and 390 px.
 *   E4   after the seal, „Alle N Kennungen ansehen“ lists every vote id of the
 *        round, sorted; the member's own id is marked by the word „deine“
 *        and an orange border; the search finds it in the browser, says so in
 *        an aria-live region, and the typed id never leaves the browser.
 *   Q12  at 390 px no sentence runs over 3 lines; at 820 px and wider no line
 *        of running text holds more than 75 characters, and every paragraph
 *        of running text holds at least 45: each block of the side column
 *        („Dein Stand“) and each `p` or `dd` of the main column (the
 *        timeline). Measured in five states: tie-decided, measure,
 *        runoff-pending, unreadable and off-roll (the side column's notice
 *        for a member not on the roll).
 *   Q9b  every `<summary>` the keyboard reaches on the member page, „Alle N
 *        Kennungen ansehen“ among them, shows the same 2px orange outline on
 *        keyboard focus (each carries its own focus-visible classes).
 *   Q20  with reduced motion asked for, document.getAnimations() is empty on
 *        the member page, Flux's hidden loading spinners included; without
 *        it the same probe sees them run (the positive control).
 *
 * Mutations seen red (j2d, 2026-11-16): the ring selector cut back to buttons
 * and checkboxes; the npub fallback cut to 24 characters again; the own-id
 * border dropped to the plain one; the side column back at 20rem; the
 * reduced-motion rule scoped to .election-ui instead of the whole document.
 * j2d2 (2026-11-16): the „Zustand nach der Wahl“ rows back on the viewport
 * breakpoint sm:grid-cols-[12rem_…] (dd 20 characters at 1280 px); the
 * off-roll notice back beside its icon (43 characters); the focus-visible
 * classes cut from the summary of the sealed id list.
 */

const ORANGE = 'rgb(247, 147, 26)'

/** Tab through the page; per focused control inside .election-ui its kind and outline. */
async function tabRings(page, presses = 60) {
    await page.evaluate(() => document.activeElement?.blur())
    const seen = new Map()

    for (let i = 0; i < presses; i++) {
        await page.keyboard.press('Tab')
        const ring = await page.evaluate(() => {
            const element = document.activeElement

            if (! element?.closest('.election-ui') || element.closest('.ballot-sheet')) {
                return null
            }

            const style = getComputedStyle(element)
            const kind = element.matches('[data-flux-button]') ? 'flux-button'
                : element.matches('[data-flux-checkbox]') ? 'flux-checkbox'
                    : element.matches('[data-flux-control]') ? 'flux-field'
                        : element.matches('a[href]') ? 'link' : element.tagName.toLowerCase()

            return {
                key: `${kind} "${(element.getAttribute('aria-label') || element.textContent || element.getAttribute('name') || '').replace(/\s+/g, ' ').trim().slice(0, 40)}"`,
                kind,
                outline: `${style.outlineStyle} ${style.outlineWidth} ${style.outlineColor}`,
            }
        })

        if (ring !== null && ! seen.has(ring.key)) {
            seen.set(ring.key, ring)
        }
    }

    return [...seen.values()]
}

test('every Flux button, field and link of the member page shows the 2px orange ring', async ({ page }) => {
    const data = scenario('npub')

    await login(page, data.voters.voter1.secret, '/association/election', '[data-location="election"] button')
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/association/election')
    await page.locator(`[data-test="receipts-${data.sealed}"] summary`).click()

    const rings = await tabRings(page)
    const kinds = new Set(rings.map((ring) => ring.kind))

    for (const kind of ['flux-button', 'flux-field', 'link']) {
        expect(kinds, `a focused ${kind} on the member page`).toContain(kind)
    }

    expect(rings.filter((ring) => ['flux-button', 'flux-checkbox', 'flux-field', 'link'].includes(ring.kind) && ring.outline !== `solid 2px ${ORANGE}`)).toEqual([])
})

test('every summary of the member page shows the 2px orange ring, the sealed id list included', async ({ page }) => {
    const data = scenario('npub')

    await login(page, data.voters.voter1.secret, '/association/election', '[data-location="election"] button')
    await page.setViewportSize({ width: 1280, height: 900 })
    await page.goto('/association/election')
    await expect(page.locator(`[data-test="receipts-${data.sealed}"] summary`)).toBeVisible()

    // Reached by Tab, so each focus is a keyboard one and :focus-visible holds.
    const summaries = (await tabRings(page, 80)).filter((ring) => ring.kind === 'summary')

    expect(summaries.map((ring) => ring.key), 'the sealed id list among the focused summaries').toContain('summary "Alle 3 Kennungen ansehen"')
    expect(summaries.filter((ring) => ring.outline !== `solid 2px ${ORANGE}`)).toEqual([])
})

test('a Flux checkbox of an election page shows the 2px orange ring', async ({ page }) => {
    const data = scenario('tie')

    await login(page, data.board.secret, '/association/election/admin')
    await page.locator('[data-test="tab-live"]').click()
    // Opened from the keyboard: the dialog puts the focus on its first checkbox
    // (autofocus), and after a key press that focus is a visible one.
    await page.locator(`[data-test="casting-vote-${data.round}"]`).focus()
    await page.keyboard.press('Enter')
    await expect(page.locator('[data-test="casting-vote-form"]')).toBeVisible()

    const checkbox = page.locator('[data-test="casting-vote-form"] [data-flux-checkbox]').first()
    await expect(checkbox).toBeFocused()
    expect(await checkbox.evaluate((element) => element.matches(':focus-visible'))).toBe(true)
    expect(await checkbox.evaluate((element) => {
        const style = getComputedStyle(element)

        return `${style.outlineStyle} ${style.outlineWidth} ${style.outlineColor}`
    })).toBe(`solid 2px ${ORANGE}`)
})

test('a candidate without a name is shown by the whole npub, wrapped, at 320 and 390 px', async ({ page }) => {
    const data = scenario('npub')
    const npub = data.bare.npub

    await login(page, data.voters.voter1.secret, '/association/election', '[data-location="election"] button')

    for (const width of [320, 390, 1280]) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto('/association/election')
        await expect(page.locator('[x-data^="electionBallot"]')).toBeVisible()
        await page.evaluate(() => document.fonts.ready)

        const found = await page.evaluate((npub) => {
            const hits = []
            const walker = document.createTreeWalker(document.querySelector('.election-ui'), NodeFilter.SHOW_TEXT)

            while (walker.nextNode()) {
                const node = walker.currentNode
                const element = node.parentElement

                if (! node.textContent.includes(npub.slice(0, 20)) || ! element.checkVisibility()) {
                    continue
                }

                const box = element.getBoundingClientRect()
                const container = element.parentElement.getBoundingClientRect()

                hits.push({
                    where: element.closest('[data-test], label, th, li')?.tagName.toLowerCase(),
                    whole: node.textContent.includes(npub),
                    cut: /npub1\w*(…|\.\.\.)/.test(node.textContent),
                    overflows: element.scrollWidth > element.clientWidth + 1 || box.right > container.right + 1 || box.right > window.innerWidth,
                })
            }

            return { hits, scrollWidth: document.documentElement.scrollWidth }
        }, npub)

        const at = `npub fallback at ${width} px`
        // On the ballot of the open round and in the counted round's table at least.
        expect(found.hits.length, `${at}: places`).toBeGreaterThanOrEqual(2)
        expect(found.hits.filter((hit) => ! hit.whole || hit.cut), `${at}: cut`).toEqual([])
        expect(found.hits.filter((hit) => hit.overflows), `${at}: overflow`).toEqual([])
        expect(found.scrollWidth, `${at}: page width`).toBeLessThanOrEqual(width)
    }
})

test('the sealed id list marks the own id and finds it in the browser without a request', async ({ page }) => {
    const data = scenario('npub')

    await login(page, data.voters.voter1.secret, '/association/election', '[data-location="election"] button')
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/association/election')

    const list = page.locator(`[data-test="receipts-${data.sealed}"]`)
    await expect(list.locator('summary')).toHaveText('Alle 3 Kennungen ansehen')
    await list.locator('summary').click()

    // Every vote of the round, sorted, and the own one marked by word and border.
    const ids = await list.locator('[data-receipt]').evaluateAll((items) => items.map((item) => item.dataset.receipt))
    expect(ids).toHaveLength(3)
    expect(ids).toEqual([...ids].sort())

    const own = list.locator('[data-own-receipt]')
    await expect(own).toHaveCount(1)
    await expect(own).toContainText('deine')
    expect(await own.evaluate((item) => getComputedStyle(item).borderTopColor)).toBe(ORANGE)
    expect(await list.locator('[data-receipt]:not([data-own-receipt])').first().evaluate((item) => getComputedStyle(item).borderTopColor)).not.toBe(ORANGE)

    const ownId = await own.getAttribute('data-receipt')
    const verdict = list.locator('[data-test="receipt-verdict"]')
    await expect(verdict).toHaveAttribute('aria-live', 'polite')

    // Nothing that is typed leaves the browser: no request carries it. The
    // page's own 5 s poll may run meanwhile; it only syncs the rounds.
    const requests = []
    page.on('request', (request) => requests.push(`${request.url()} ${request.postData() ?? ''}`))

    const search = list.locator('[data-test="receipt-search"] input, input[data-test="receipt-search"]').first()
    await search.fill(`${ownId.slice(0, 8)}…${ownId.slice(-8)}`)
    await expect(verdict).toHaveText('Gefunden: Diese Kennung steht in der Liste.')
    await expect(own).toHaveClass(/outline-orange-500/)

    await search.fill('0123456789abcdef')
    await expect(verdict).toHaveText(ids.some((id) => id.includes('0123456789abcdef')) ? 'Gefunden: Diese Kennung steht in der Liste.' : 'Diese Kennung steht nicht in der Liste.')

    await search.fill(ownId)
    await expect(verdict).toHaveText('Gefunden: Diese Kennung steht in der Liste.')

    expect(requests.filter((request) => request.includes(ownId.slice(0, 8)) || request.includes('0123456789abcdef'))).toEqual([])
    expect(requests.filter((request) => ! request.includes('syncRounds'))).toEqual([])

    // A re-render keeps the list open and the verdict standing.
    await page.evaluate(() => Promise.all(window.Livewire.all().map((component) => component.$wire.$refresh())))
    await page.waitForLoadState('networkidle')
    await expect(list).toHaveAttribute('open', '')
    await expect(verdict).toHaveText('Gefunden: Diese Kennung steht in der Liste.')
})

/**
 * Per leaf text block of the member page: its longest line, its capacity in
 * characters, and the most lines any one of its sentences takes. A block is
 * the nearest ancestor that is not inline, so a `span.block` is a block of
 * its own.
 */
function textBlocks() {
    const root = document.querySelector('.election-ui')
    const blockOf = (node) => {
        let element = node.parentElement

        while (element !== root && getComputedStyle(element).display.startsWith('inline')) {
            element = element.parentElement
        }

        return element
    }
    const units = new Map()
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)

    while (walker.nextNode()) {
        const node = walker.currentNode

        if (node.textContent.trim() === '' || ! node.parentElement.checkVisibility() || node.parentElement.closest('.sr-only, h1, h2, h3, th, td, summary, button, label, [data-receipt], .ballot-sheet ul, pre')) {
            continue
        }

        const block = blockOf(node)

        if (! units.has(block)) {
            units.set(block, [])
        }

        for (let i = 0; i < node.length; i++) {
            const range = document.createRange()
            range.setStart(node, i)
            range.setEnd(node, i + 1)
            const rect = range.getClientRects()[0]

            if (rect && rect.width > 0) {
                units.get(block).push({ character: node.textContent[i], top: rect.top, height: rect.height })
            }
        }
    }

    const blocks = []

    for (const [block, characters] of units) {
        if (characters.length < 20) {
            continue
        }

        let line = 0
        let top = characters[0].top
        const perLine = [0]

        for (const character of characters) {
            if (character.top > top + character.height / 2) {
                line++
                top = character.top
                perLine[line] = 0
            }

            character.line = line
            perLine[line]++
        }

        const text = characters.map((character) => character.character).join('')
        let sentenceLines = 0

        for (const match of text.matchAll(/[^.!?]+(?:[.!?]+(?=\s|$)|$)/g)) {
            if (match[0].trim() !== '') {
                sentenceLines = Math.max(sentenceLines, new Set(characters.slice(match.index, match.index + match[0].length).map((character) => character.line)).size)
            }
        }

        const probe = document.createElement('span')
        probe.textContent = '0'.repeat(50)
        probe.style.cssText = 'position:absolute;visibility:hidden;white-space:nowrap'
        block.appendChild(probe)
        const advance = probe.getBoundingClientRect().width / 50
        probe.remove()
        const style = getComputedStyle(block)

        blocks.push({
            text: text.trim().slice(0, 50),
            aside: block.closest('aside') !== null,
            prose: block.closest('p, dd') !== null,
            longestLine: Math.max(...perLine),
            capacity: Math.floor((block.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight)) / advance),
            sentenceLines,
        })
    }

    return blocks
}

// The decided tie (a 63-character name, a 60-character chair); a counted
// round next to an open one the member has just voted in (the receipt); a
// close without a majority, before the run-off („Stand jetzt“, a seat still
// open); a round that cannot be counted; and a member who paid after the
// round opened, so the side column says she is not on its roll.
const LINE_STATES = [
    { name: 'tie-decided', ready: '[data-test^="casting-vote-"]', viewer: (data) => data.voters.voter1 },
    { name: 'measure', ready: '[data-test="receipt-id"]', viewer: (data) => data.voters.voter1 },
    { name: 'runoff-pending', ready: '[data-test="after-election-pending"]', viewer: (data) => data.voters.voter1 },
    { name: 'unreadable', ready: '[data-test^="unreadable-"]', viewer: (data) => data.voters.voter1 },
    { name: 'off-roll', ready: '[data-test="eligibility-off-roll"]', viewer: (data) => data.late },
]

for (const { name, ready, viewer } of LINE_STATES) {
test(`sentences run at most 3 lines at 390 px, and lines hold 45 to 75 characters from 820 px (${name})`, async ({ page }) => {
    const data = scenario(name)

    await login(page, viewer(data).secret, '/association/election', '[data-location="election"] button')

    for (const width of [390, 820, 1280, 1600]) {
        await page.setViewportSize({ width, height: 900 })
        await page.goto('/association/election')
        await expect(page.locator(ready).first()).toBeVisible()
        await page.evaluate(() => document.fonts.ready)

        const blocks = await page.evaluate(textBlocks)
        const at = `member page (${name}) at ${width} px`

        if (width === 390) {
            expect.soft(blocks.filter((block) => block.sentenceLines > 3).map((block) => `${block.sentenceLines} lines: ${block.text}`), `${at}: sentences over 3 lines`).toEqual([])
        } else {
            expect.soft(blocks.filter((block) => block.longestLine > 75).map((block) => `${block.longestLine}: ${block.text}`), `${at}: lines over 75 characters`).toEqual([])
            expect.soft(blocks.filter((block) => block.capacity > 75).map((block) => `${block.capacity}: ${block.text}`), `${at}: room for over 75 characters`).toEqual([])
        }

        if (width >= 820) {
            const prose = blocks.filter((block) => block.prose && ! block.aside)
            const aside = blocks.filter((block) => block.aside)
            const span = (list) => list.length === 0 ? null : { min: Math.min(...list.map((block) => block.capacity)), max: Math.max(...list.map((block) => block.capacity)), blocks: list.length }

            keep({ page: 'member-lines', scenario: name, viewport: { width, height: 900 }, main_p_dd_capacity: span(prose), aside_capacity: span(aside) })

            expect.soft(aside.filter((block) => block.capacity < 45).map((block) => `${block.capacity}: ${block.text}`), `${at}: side column under 45 characters`).toEqual([])
            expect.soft(prose.filter((block) => block.capacity < 45).map((block) => `${block.capacity}: ${block.text}`), `${at}: main column p/dd under 45 characters`).toEqual([])
        }
    }
})
}

test('with reduced motion nothing animates on the member page, and without it the probe sees the spinners', async ({ page }) => {
    const data = scenario('npub')

    await login(page, data.voters.voter1.secret, '/association/election', '[data-location="election"] button')

    const running = async (reducedMotion) => {
        await page.emulateMedia({ reducedMotion })
        await page.goto('/association/election')
        await expect(page.locator('[x-data^="electionBallot"]')).toBeVisible()
        await page.waitForLoadState('networkidle')

        return page.evaluate(() => document.getAnimations().map((animation) => `${animation.animationName ?? animation.transitionProperty} on ${animation.effect?.target?.closest('[data-flux-loading-indicator], [data-flux-button]')?.textContent?.trim().slice(0, 30) ?? animation.effect?.target?.tagName}`))
    }

    // Positive control: Flux keeps a spinner running in every hidden loading indicator.
    expect((await running('no-preference')).length).toBeGreaterThan(0)
    expect(await running('reduce')).toEqual([])
})
