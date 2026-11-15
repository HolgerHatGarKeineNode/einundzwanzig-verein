/**
 * The live ballot of one open election round
 * (resources/views/components/election-ballot.blade.php).
 *
 * fill → review (the server builds the unsigned event and its readable text)
 * → signing (NIP-07 window.nostr.signEvent) → sending (CastBallot) → the page
 * re-renders into "gezählt". The selection lives here only; the server sees
 * it once, inside the signed event.
 *
 * A lost answer is not a failed vote: the signed event is kept, the server is
 * asked whether it arrived, and "Noch einmal senden" re-sends the SAME event —
 * the server recognises it and reports it as counted, never twice.
 */
import { signerKind, withTimeout } from './nostrSigner.js'

const ANSWER_TIMEOUT_MS = 20000

// A signer that never answers (a bunker whose relay is gone) would leave the
// step at "Warte auf deinen Signer …" until a reload. One minute is enough to
// switch to a signer app and approve.
const SIGNER_TIMEOUT_MS = 60000

export default ({ roundId, pubkey, rule, max, messages }) => ({
    // fill | review | signing | sending | checking | lost
    step: 'fill',
    busy: false,
    // candidate id => 'for' | 'yes' | 'no' | 'abstain'
    selection: {},
    // single_majority: a candidate id or 'blank'
    single: null,
    template: null,
    content: '',
    signed: null,
    error: '',
    notice: '',
    // No usable signer on this device: the German help is shown.
    needsSigner: false,
    // Counts signing attempts; a signer answer for an older attempt is dropped.
    signAttempt: 0,

    /** What goes to the server: candidate id => for|yes|no; empty is a blank vote. */
    picked() {
        if (rule === 'single_majority') {
            return this.single !== null && this.single !== 'blank' ? { [this.single]: 'for' } : {}
        }

        return Object.fromEntries(Object.entries(this.selection).filter(([, choice]) => ['for', 'yes', 'no'].includes(choice)))
    },

    summaryLine() {
        const count = Object.keys(this.picked()).length

        if (rule === 'yes_no_per_candidate') {
            return count === 0 ? messages.lineNone : messages.lineMarked.replace(':count', count)
        }

        if (rule === 'single_majority') {
            // The cross on the sheet says it; a blank vote is a box of its own.
            return ''
        }

        return count === 0 ? messages.lineBlank : messages.lineCount.replace(':count', count).replace(':max', max)
    },

    toggle(candidateId, event) {
        if (event.target.checked) {
            if (Object.keys(this.picked()).length >= max) {
                event.target.checked = false
                this.notice = messages.tooMany
                return
            }
            this.selection[candidateId] = 'for'
        } else {
            delete this.selection[candidateId]
        }
        this.notice = ''
    },

    async review() {
        if (this.busy) {
            return
        }

        this.error = ''
        this.notice = ''
        this.busy = true

        try {
            const answer = await withTimeout(this.$wire.prepareBallot(roundId, this.picked()), ANSWER_TIMEOUT_MS)

            if (!answer || answer.message || !answer.event) {
                this.error = answer?.message ?? messages.noAnswer
                return
            }

            this.template = answer.event
            this.content = answer.event.content
            this.step = 'review'
            // After x-show has revealed the review: the heading names the step.
            this.$nextTick(() => requestAnimationFrame(() => this.$refs.reviewHeading?.focus()))
        } catch (error) {
            this.error = messages.noAnswer
        } finally {
            this.busy = false
        }
    },

    back() {
        this.step = 'fill'
        this.error = ''
        this.needsSigner = false
        this.$nextTick(() => this.$refs.sheet?.focus())
    },

    fail(message) {
        // A late signer answer of this attempt no longer counts.
        this.signAttempt++
        this.step = 'review'
        this.error = message
    },

    /**
     * @param {boolean} force  the member chose "Signer-App verbinden" in the
     *                         help and expects the window.nostr.js dialog
     */
    async sign(force = false) {
        this.error = ''

        // window.nostr.js always defines window.nostr; without a real signer
        // its English dialog with an nsec field would open here.
        if ((!force && signerKind() === 'none') || !window.nostr || typeof window.nostr.signEvent !== 'function') {
            this.needsSigner = true
            this.fail(messages.noSigner)
            return
        }

        this.needsSigner = false
        this.step = 'signing'
        const attempt = ++this.signAttempt
        let signed

        try {
            if (pubkey && typeof window.nostr.getPublicKey === 'function') {
                const key = await withTimeout(window.nostr.getPublicKey(), SIGNER_TIMEOUT_MS)
                if (attempt !== this.signAttempt) {
                    return
                }
                if (key && key !== pubkey) {
                    this.fail(messages.otherAccount)
                    return
                }
            }

            // Extensions may hand back proxies Livewire cannot serialise.
            signed = JSON.parse(JSON.stringify(await withTimeout(window.nostr.signEvent({ ...this.template, tags: this.template.tags.map((tag) => [...tag]) }), SIGNER_TIMEOUT_MS)))
        } catch (error) {
            if (attempt !== this.signAttempt) {
                return
            }
            this.fail(error?.message === 'timeout' ? messages.signerTimeout : messages.cancelled)
            return
        }

        if (attempt !== this.signAttempt) {
            return
        }

        if (!signed || typeof signed.sig !== 'string' || typeof signed.id !== 'string') {
            this.fail(messages.cancelled)
            return
        }

        if (pubkey && signed.pubkey !== pubkey) {
            this.fail(messages.otherAccount)
            return
        }

        this.signed = signed
        await this.send()
    },

    async send() {
        this.error = ''
        this.step = 'sending'
        let answer

        try {
            answer = await withTimeout(this.$wire.castBallot(roundId, this.signed), ANSWER_TIMEOUT_MS)
        } catch (error) {
            await this.check()
            return
        }

        if (!answer || typeof answer !== 'object') {
            await this.check()
            return
        }

        // counted / voted / closed: the server re-renders this round.
        if (answer.status === 'refused') {
            this.fail(answer.message ?? messages.noAnswer)
        }
    },

    async check() {
        this.step = 'checking'

        try {
            const state = await withTimeout(this.$wire.checkBallot(roundId, this.signed.id), ANSWER_TIMEOUT_MS)

            if (state === 'none') {
                this.step = 'lost'
                this.error = messages.notArrived
            }
            // counted / voted: the server re-renders this round.
        } catch (error) {
            this.step = 'lost'
            this.error = messages.stillNoAnswer
        }
    },
})
