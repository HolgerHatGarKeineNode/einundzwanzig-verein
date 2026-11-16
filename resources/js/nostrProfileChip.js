/**
 * The Nostr name chip (resources/views/components/nostr-profile.blade.php).
 *
 * No child of the chip carries an Alpine expression: when Livewire morphs a
 * section in, Alpine evaluates the children before the chip's own x-data
 * exists, and those effects stay dead (measured: "html is not defined", the
 * card opened empty). So the chip wires the trigger, the panel and its
 * content by hand.
 *
 * The panel is a native popover (top layer, light dismiss, Escape); it is
 * placed below the trigger, or above it when there is no room.
 */
export default (url) => ({
    loading: false,
    loaded: false,

    // Looked up when needed and wired by delegation on the chip itself, so it does not matter whether
    // the children are in the DOM yet when init() runs.
    get trigger() {
        return this.$el.querySelector('[data-chip-trigger]')
    },

    get panel() {
        return this.$el.querySelector('[data-chip-panel]')
    },

    init() {
        this.place = this.place.bind(this)
        // The native light dismiss does not always see Escape (pages with their own key handlers,
        // measured on the election admin page), so the chip closes its card itself.
        this.escape = (event) => {
            if (event.key === 'Escape' && this.panel?.matches(':popover-open')) {
                this.panel.hidePopover()
                this.trigger?.focus()
            }
        }

        this.$el.addEventListener('click', (event) => {
            if (event.target.closest('[data-chip-trigger], [data-chip-retry]')) {
                this.load()
            } else if (event.target.closest('[data-chip-panel]') && ! event.target.closest('a, button, input')) {
                // On the ballot the chip sits in a <label>: a click on the card's text must not pick the candidate.
                event.preventDefault()
            }
        })

        // `toggle` does not bubble; the capture phase still sees it on the way down.
        this.$el.addEventListener('toggle', (event) => {
            if (! event.target.matches?.('[data-chip-panel]')) {
                return
            }

            if (event.newState === 'open') {
                this.place()
                window.addEventListener('resize', this.place)
                window.addEventListener('scroll', this.place, true)
                window.addEventListener('keydown', this.escape, true)
            } else {
                window.removeEventListener('resize', this.place)
                window.removeEventListener('scroll', this.place, true)
                window.removeEventListener('keydown', this.escape, true)
            }
        }, true)
    },

    destroy() {
        window.removeEventListener('resize', this.place)
        window.removeEventListener('scroll', this.place, true)
        window.removeEventListener('keydown', this.escape, true)
    },

    place() {
        const gap = 6
        const edge = 8
        const anchor = this.trigger.getBoundingClientRect()
        const width = this.panel.offsetWidth
        const height = this.panel.offsetHeight

        const left = Math.min(Math.max(edge, anchor.left), window.innerWidth - width - edge)
        let top = anchor.bottom + gap

        if (top + height > window.innerHeight - edge) {
            top = anchor.top - gap - height >= edge ? anchor.top - gap - height : Math.max(edge, window.innerHeight - edge - height)
        }

        this.panel.style.left = `${Math.max(edge, left)}px`
        this.panel.style.top = `${top}px`
    },

    show(state) {
        this.panel.querySelector('[data-chip-loading]').hidden = state !== 'loading'
        this.panel.querySelector('[data-chip-failed]').hidden = state !== 'failed'
    },

    load() {
        if (this.loading || this.loaded) {
            return
        }

        this.loading = true
        this.show('loading')

        fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.text() : Promise.reject(response.status)))
            .then((html) => {
                this.panel.querySelector('[data-chip-body]').innerHTML = html
                this.loaded = true
                this.show('done')
                requestAnimationFrame(() => this.panel.matches(':popover-open') && this.place())
            })
            .catch(() => this.show('failed'))
            .finally(() => { this.loading = false })
    },
})
