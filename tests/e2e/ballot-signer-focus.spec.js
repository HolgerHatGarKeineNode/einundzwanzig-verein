import { test, expect, scenario, login } from './support/election.js'

/**
 * Where the focus goes on the ballot when signing cannot go ahead
 * (resources/js/electionBallot.js):
 *
 *   F1  No signer on this device (the window.nostr.js fallback, `isWnj`):
 *       „Signieren und abgeben“ shows the German help, and the focus moves
 *       onto it, as the login and the signer check do.
 *   F2  The signer never answers: after the 60 s timeout the focus is on
 *       „Signieren und abgeben“ again, not on BODY (the button was disabled
 *       while the signer was asked).
 *
 * Run by scripts/e2e-election.sh like the other election specs: scenario
 * "member" of tests/e2e/support/election-fixture.php seeds one open round
 * with the voters on its roll. A voter logs in through the page's own login
 * on a second page of the same context, which then closes: the session
 * cookie belongs to the context, the test signer stayed on that page, so the
 * test's page has no signer and keeps the vendor window.nostr.js fallback.
 * Both pages are watched (support/channels.js) and behind the network guard
 * of support/election.js.
 */

test.use({ viewport: { width: 390, height: 844 } })

/** What has the focus: tag, id, data-signer-help, and its first line of text. */
async function focused(page) {
    return page.evaluate(() => {
        const element = document.activeElement

        return {
            tag: element?.tagName ?? null,
            id: element?.id ?? '',
            signerHelp: element?.hasAttribute('data-signer-help') ?? false,
            text: (element?.innerText ?? '').trim().split('\n')[0],
        }
    })
}

test.beforeEach(async ({ context }) => {
    const data = scenario('member')
    const loginPage = await context.newPage()

    // Logged in at desktop width: at 390 px the first visible login button is
    // the one in the off-canvas sidebar, and a click on it never lands.
    await loginPage.setViewportSize({ width: 1280, height: 900 })
    await login(loginPage, data.voters.voter1.secret, '/association/election')
    await loginPage.close()
})

/** The election page, ballot filled in blank and on the review step. */
async function openReview(page) {
    await page.goto('/association/election')
    await page.getByRole('button', { name: 'Prüfen' }).click()
    await expect(page.getByRole('heading', { name: 'Prüfe deine Stimme' })).toBeVisible()
}

test('F1: without a signer the focus moves onto the German help', async ({ page }) => {
    await openReview(page)
    expect(await page.evaluate(() => window.nostr?.isWnj === true)).toBe(true)

    await page.getByRole('button', { name: 'Signieren und abgeben' }).click()
    await expect(page.getByRole('heading', { name: 'Noch kein Nostr-Signer?' })).toBeVisible()

    await expect.poll(() => focused(page)).toMatchObject({ tag: 'SECTION', signerHelp: true })
})

test('F2: after the signer timeout the focus is on the sign button again', async ({ page }) => {
    // Installed before the page loads, so the 60 s of the signer timeout can be skipped.
    // A faked clock also holds Alpine's $nextTick (a setTimeout) until it is run on.
    await page.clock.install()
    await openReview(page)
    // A real signer that never answers; assigning drops the vendor's isWnj mark.
    await page.evaluate(() => {
        window.nostr = { getPublicKey: () => new Promise(() => {}), signEvent: () => new Promise(() => {}) }
    })

    await page.getByRole('button', { name: 'Signieren und abgeben' }).click()
    await expect(page.getByRole('list', { name: 'Wahlgänge' }).getByText('Warte auf deinen Signer …')).toBeVisible()
    expect((await focused(page)).tag).toBe('BODY')

    await page.clock.runFor(60_000)
    await page.clock.runFor(100)
    await expect(page.getByRole('list', { name: 'Wahlgänge' }).getByText('Dein Signer hat nach einer Minute nicht geantwortet.', { exact: false })).toBeVisible()

    await expect.poll(() => focused(page)).toMatchObject({ tag: 'BUTTON', text: 'Signieren und abgeben' })
})
