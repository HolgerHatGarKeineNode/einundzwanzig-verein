import { test, expect, scenario, installSigner, tagsNamed } from './support/election.js'

/**
 * The member's way through an election, in a real browser: what Pest cannot
 * see is the JavaScript that builds and signs the two events of the flow.
 *
 * Two client-side guarantees are pinned here and nowhere else:
 *
 *   - the login event carries exactly one ['u', <app origin>] tag
 *     (resources/js/nostrLogin.js). Without it a NIP-42 relay AUTH answer
 *     could be replayed as a login; the server refuses an event without it.
 *   - the vote event carries exactly one ['nonce', <64 lowercase hex>] tag,
 *     drawn in the browser (resources/js/electionBallot.js). Without it the
 *     event id on the receipt could be brute-forced back to the ballot.
 *
 * Mutations seen red (j1d, 2026-11-15): the `u` tag removed from the login
 * event in nostrLogin.js, and the nonce tag removed from the vote event in
 * electionBallot.js, each with `npm run build` before the run.
 */

const ELECTION = '/association/election'

test('a guest is asked to log in on the page and sees the German help instead of the vendor dialog', async ({ page }) => {
    scenario('member')

    await page.goto(ELECTION)

    const cta = page.locator('[data-location="election"] button').filter({ visible: true })
    await expect(cta).toBeVisible()
    await expect(page.locator('#guest-signer-help')).toBeVisible()

    // The vendor window.nostr.js is loaded and no extension is there: the
    // German help takes the focus, and no relay socket opens (the guard
    // reports any WebSocket).
    await cta.click()
    await expect(page.locator('#guest-signer-help')).toBeFocused()
    await expect(page.locator('[role="status"] span').filter({ visible: true })).toHaveCount(1)
    await expect(page.locator('form[action$="/logout"]')).toHaveCount(0)
})

test('a member logs in, fills the ballot, reviews, casts and sees the receipt of exactly that vote', async ({ page, baseURL }) => {
    const data = scenario('member')
    const voter = data.voters.voter1

    const signer = await installSigner(page, voter.secret)
    await page.goto(ELECTION)
    await page.locator('[data-location="election"] button').filter({ visible: true }).click()
    await expect(page.locator('form[action$="/logout"]').first()).toBeAttached({ timeout: 15000 })

    // The login event: kind 22242, the session's challenge, and exactly one
    // `u` tag naming this app's origin.
    const loginEvent = signer.signed.find((event) => event.kind === 22242)
    expect(loginEvent, 'the login signed no kind 22242 event').toBeTruthy()
    expect(tagsNamed(loginEvent, 'challenge')).toHaveLength(1)
    expect(tagsNamed(loginEvent, 'u')).toEqual([['u', new URL(baseURL).origin]])
    expect(tagsNamed(loginEvent, 'relay')).toEqual([])

    // The ballot of the open round, "Vorsitz", single majority.
    const ballot = page.locator('[x-data^="electionBallot"]')
    await expect(ballot).toBeVisible()
    await ballot.getByText(data.candidates.anna.name).click()
    await ballot.locator('form button[type="submit"]').click()

    // Review: the readable text the server built names the choice.
    const review = ballot.locator('pre')
    await expect(review).toBeVisible()
    await expect(review).toContainText(data.candidates.anna.name)
    await expect(ballot.locator('[x-ref="reviewHeading"]')).toBeFocused()

    await ballot.locator('button[x-on\\:click="sign()"]').click()

    // Counted: the receipt names the id of the event the signer signed.
    const receipt = page.getByText(/Kennung deiner Stimme:/)
    await expect(receipt).toBeVisible({ timeout: 15000 })

    const voteEvents = signer.signed.filter((event) => event.kind !== 22242)
    expect(voteEvents).toHaveLength(1)

    const vote = voteEvents[0]
    const nonces = tagsNamed(vote, 'nonce')
    expect(nonces, 'the vote event must carry exactly one nonce tag').toHaveLength(1)
    expect(nonces[0]).toHaveLength(2)
    expect(nonces[0][1]).toMatch(/^[0-9a-f]{64}$/)
    expect(tagsNamed(vote, 'round')).toEqual([['round', String(data.round)]])

    // The signed id as the extension computed it, shortened as on the page.
    const { getEventHash } = await import('nostr-tools/pure')
    const id = getEventHash({ ...vote, pubkey: signer.pubkey })
    await expect(receipt).toContainText(`${id.slice(0, 8)}…${id.slice(-8)}`)
    await expect(receipt).toBeInViewport()

    // The ballot is gone: a second vote in this round is not offered.
    await expect(page.locator('[x-data^="electionBallot"]')).toHaveCount(0)

    // And it stays that way after a reload.
    await page.reload()
    await expect(page.getByText(/Kennung deiner Stimme:/)).toContainText(`${id.slice(0, 8)}…${id.slice(-8)}`)
})
