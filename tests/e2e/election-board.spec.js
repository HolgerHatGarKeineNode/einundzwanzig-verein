import { test, expect, scenario, castVotes, login } from './support/election.js'

/**
 * The board's way through the meeting, in a real browser: the round modal,
 * the Livewire roundtrips behind every button, and what the live section
 * shows afterwards. The rules behind each step are pinned in Pest
 * (tests/Feature/Services/Election, tests/Feature/Livewire/Association/
 * Election); this spec pins that the page drives them without a console
 * error, an exception or a failed response (tests/e2e/support/channels.js).
 */

const ADMIN = '/association/election/admin'

async function openSection(page, key) {
    await page.locator(`[data-test="tab-${key}"]`).click()
    await expect(page.locator(`[data-test="tab-${key}"]`)).toHaveAttribute('aria-current', 'page')
}

/** Confirm the round modal, with a reason when the action takes one. */
async function confirmRoundAction(page, reason = null) {
    const modal = page.locator('[data-test="round-action-form"]')
    await expect(modal).toBeVisible()

    if (reason !== null) {
        await modal.locator('textarea').fill(reason)
    }

    await modal.locator('[data-test="confirm-round-action"]').click()
    await expect(modal).toBeHidden()
}

function liveRound(page, id) {
    return page.locator(`[data-test="live-round-${id}"]`)
}

test('the board opens a round, closes it with a reason and sees the counted result', async ({ page }) => {
    const data = scenario('board-close')
    const { anna, bernd } = data.candidates

    await login(page, data.board.secret, ADMIN)
    await openSection(page, 'live')
    await expect(liveRound(page, data.round)).toHaveAttribute('data-state', 'draft')

    await page.locator(`[data-test="open-round-${data.round}"]`).click()
    await confirmRoundAction(page)
    await expect(liveRound(page, data.round)).toHaveAttribute('data-state', 'open')

    // Three members vote while the round is open: two for Anna, one for Bernd.
    castVotes(data.round, anna.id, 2)
    castVotes(data.round, bernd.id, 1)

    await page.locator(`[data-test="close-round-${data.round}"]`).click()
    await confirmRoundAction(page, 'Alle Anwesenden haben abgestimmt')

    await expect(liveRound(page, data.round)).toHaveAttribute('data-state', 'closed')
    await expect(page.locator(`[data-test="live-outcome-${data.round}"]`)).toContainText(anna.name)

    const result = page.locator(`[data-test="live-result-${data.round}"]`)
    await expect(result.locator('li').filter({ hasText: anna.name }).locator('.tabular-nums')).toHaveText(/^\s*2 Stimmen\s*$/)
    await expect(result.locator('li').filter({ hasText: bernd.name }).locator('.tabular-nums')).toHaveText(/^\s*1 Stimme\s*$/)
})

test('the board annuls an open round and repeats it as a new round', async ({ page }) => {
    const data = scenario('board-annul')

    await login(page, data.board.secret, ADMIN)
    await openSection(page, 'live')
    await expect(liveRound(page, data.round)).toHaveAttribute('data-state', 'open')

    await page.locator(`[data-test="annul-round-${data.round}"]`).first().click()
    await confirmRoundAction(page, 'Falsche Kandidatenliste geöffnet')
    await expect(liveRound(page, data.round)).toHaveAttribute('data-state', 'annulled')

    await page.locator(`[data-test="repeat-round-${data.round}"]`).click()
    await confirmRoundAction(page)

    // The repetition is a round of its own, waiting to be opened; the
    // annulled one stays on the list.
    await expect(page.locator('[data-test^="live-round-"]')).toHaveCount(2)
    await expect(liveRound(page, data.round)).toHaveAttribute('data-state', 'annulled')

    const repeated = page.locator(`[data-test^="live-round-"]:not([data-test="live-round-${data.round}"])`)
    await expect(repeated).toHaveAttribute('data-state', 'draft')
    await expect(repeated.locator('[data-test^="open-round-"]')).toBeVisible()
    await expect(page.locator(`[data-test="repeat-round-${data.round}"]`)).toHaveCount(0)
})

test('the board strikes a round nobody can stand in any more', async ({ page }) => {
    const data = scenario('board-strike')

    await login(page, data.board.secret, ADMIN)

    // The next step names the dead end and offers the way out.
    const nextStep = page.locator('[data-test="next-step"]')
    await expect(nextStep).toBeVisible()
    await nextStep.getByRole('button', { name: 'Wahlgang streichen' }).click()
    await confirmRoundAction(page, 'Niemand kann mehr antreten')

    await openSection(page, 'live')
    await expect(liveRound(page, data.dead)).toHaveAttribute('data-state', 'annulled')
    await expect(page.locator(`[data-test="open-round-${data.dead}"]`)).toHaveCount(0)
    await expect(nextStep.getByRole('button', { name: 'Wahlgang streichen' })).toHaveCount(0)
})

test('the board enters a cash payer as entitled, and the next round puts her on its roll', async ({ page }) => {
    const data = scenario('board-entitle')

    await login(page, data.board.secret, ADMIN)
    await openSection(page, 'roll')

    const form = page.locator('[data-test="entitle-form"]')
    const member = form.locator('select')
    await expect(member.locator('option', { hasText: data.cash.name })).toHaveCount(1)

    await member.selectOption({ label: data.cash.name })
    await form.locator('input').fill('Beitrag bar bezahlt am 3. März')
    await form.locator('[data-test="entitle-submit"]').click()

    // Entered once: she is no longer offered.
    await expect(member.locator('option', { hasText: data.cash.name })).toHaveCount(0)

    await openSection(page, 'live')
    await page.locator(`[data-test="open-round-${data.round}"]`).click()
    await confirmRoundAction(page)
    await expect(liveRound(page, data.round)).toHaveAttribute('data-state', 'open')

    await openSection(page, 'roll')
    await expect(page.locator('[data-test="roll-row"]').filter({ hasText: data.cash.name })).toHaveCount(1)
})

test('the board enters the chair\'s casting vote on a tie, and the share view shows the decided result', async ({ page, context }) => {
    const data = scenario('board-close')
    const { anna, bernd } = data.candidates

    await login(page, data.board.secret, ADMIN)
    await openSection(page, 'live')
    await page.locator(`[data-test="open-round-${data.round}"]`).click()
    await confirmRoundAction(page)

    // One vote each: a Stimmengleichheit, decided by the chair (statutes Art. 6).
    castVotes(data.round, anna.id, 1)
    castVotes(data.round, bernd.id, 1)

    await page.locator(`[data-test="close-round-${data.round}"]`).click()
    await confirmRoundAction(page, 'Alle Anwesenden haben abgestimmt')
    await expect(page.locator(`[data-test="live-tie-next-${data.round}"]`)).toHaveText('Die/der Vorsitzende fällt den Stichentscheid.')

    await page.locator(`[data-test="casting-vote-${data.round}"]`).click()
    const dialog = page.locator('[data-test="casting-vote-form"]')
    await expect(dialog).toBeVisible()
    await expect(dialog.locator('[data-test^="casting-candidate-"]').filter({ hasText: anna.name })).toContainText('1 Stimme')

    // The count rule is checked before anything is written.
    await dialog.locator('[data-test^="casting-candidate-"]').filter({ hasText: anna.name }).click()
    await dialog.locator('[data-test^="casting-candidate-"]').filter({ hasText: bernd.name }).click()
    await dialog.locator('[data-test="casting-chair"]').fill('Erika Vorsitz')
    await dialog.locator('[data-test="confirm-casting-vote"]').click()
    await expect(dialog).toContainText('Wähle genau eine Person: Es geht um einen Sitz.')

    await dialog.locator('[data-test^="casting-candidate-"]').filter({ hasText: anna.name }).click()
    await dialog.locator('[data-test="confirm-casting-vote"]').click()
    await expect(dialog).toBeHidden()

    const decided = `Stichentscheid der/des Vorsitzenden Erika Vorsitz: gewählt ist ${bernd.name}.`
    await expect(page.locator(`[data-test="live-casting-vote-${data.round}"]`)).toContainText(decided)
    await expect(page.locator(`[data-test="casting-vote-${data.round}"]`)).toHaveCount(0)

    const [screen] = await Promise.all([
        context.waitForEvent('page'),
        page.locator('[data-test="open-screen"]').click(),
    ])
    await screen.waitForLoadState()
    await expect(screen.locator('[data-test="share-outcome"]')).toContainText(bernd.name)
    await expect(screen.locator('[data-test="share-casting-vote"]')).toHaveText('Entschieden durch Stichentscheid der/des Vorsitzenden Erika Vorsitz.')
    await expect(screen.locator('[data-test="share-tie-next"]')).toHaveCount(0)
})

test('a name chip opens its profile card after the sections are switched by Livewire', async ({ page }) => {
    const data = scenario('board-close')

    await login(page, data.board.secret, ADMIN)

    for (const key of ['candidacies', 'roll', 'candidacies']) {
        await openSection(page, key)
        const chip = page.locator('[data-test="nostr-profile"]').first()
        await expect(chip).toBeVisible()
        await chip.click()
        const card = page.locator('[data-test="nostr-profile-card"]:visible')
        await expect(card, `card after switching to ${key}`).toHaveCount(1)
        await page.keyboard.press('Escape')
        await expect(card).toHaveCount(0)
    }
})
