/**
 * Which Nostr signer this page can actually use.
 *
 * The layout loads window.nostr.js (public/dist/window.nostr.min.js.js). It
 * ALWAYS defines `window.nostr`, so `!window.nostr` never tells a member
 * without a signer apart from one with a browser extension. The vendor object
 * marks itself with `isWnj: true`; an extension that injects later replaces it
 * through the vendor's own setter and drops that mark.
 *
 * - 'extension': a real NIP-07 extension (Alby, nos2x, Nostash, ...)
 * - 'remote':    the vendor fallback with a signer app already connected
 *                (it keeps the connection under `wnj:bunkerPointer`; only the
 *                presence of that key is read here, never its value)
 * - 'none':      the vendor fallback with nothing connected. Calling it would
 *                open its English dialog, which also offers an `nsec1…` field.
 */
export function signerKind() {
    const nostr = window.nostr

    if (!nostr || typeof nostr.signEvent !== 'function') {
        return 'none'
    }

    if (nostr.isWnj !== true) {
        return 'extension'
    }

    try {
        return window.localStorage.getItem('wnj:bunkerPointer') !== null ? 'remote' : 'none'
    } catch (error) {
        return 'none'
    }
}

/**
 * Reject with Error('timeout') when `promise` has not settled after `ms`.
 * The original promise keeps running; callers ignore its late answer.
 */
export function withTimeout(promise, ms) {
    let timer

    return Promise.race([
        promise,
        new Promise((_, reject) => {
            timer = setTimeout(() => reject(new Error('timeout')), ms)
        }),
    ]).finally(() => clearTimeout(timer))
}
