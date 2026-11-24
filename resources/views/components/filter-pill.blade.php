@props([
    'active' => false,
])

{{--
    A toggle in a row of filters (P3/D1), the pill of the project-support
    list: 44px tall, the pressed state told by aria-pressed and by the fill,
    never by the colour alone (the fill changes the label's colour and the
    border). Inactive: #ADADB0 on #1A1A1D = 7.76:1, border neutral-500
    #6B6B70 on the page 3.74:1. Active: #17120A on #F7931A = 8.3:1.
--}}
<button type="button"
        aria-pressed="{{ $active ? 'true' : 'false' }}"
        {{ $attributes->class([
            'inline-flex min-h-11 shrink-0 items-center gap-2 rounded-full border px-4 text-sm font-semibold transition-colors duration-150 motion-reduce:transition-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500',
            'border-orange-500 bg-orange-500 text-accent-foreground' => $active,
            'border-neutral-500 bg-bg-elevated text-text-secondary hover:border-neutral-400 hover:text-text-primary' => ! $active,
        ]) }}>
    {{ $slot }}
</button>
