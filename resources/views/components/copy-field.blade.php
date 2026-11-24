@props([
    'value',
    'label',
])

{{--
    The one copy-to-clipboard field (P3/D13): a read-only Flux input with its
    copy button, the value selectable as text when the clipboard is not
    available. Field and button are 44px tall (Flux' own: 40px field, 32px
    button). The button swaps its icon to a check once copied.
--}}
<div {{ $attributes->class(['[&_input]:h-11 [&_[data-flux-input]_button]:size-11']) }}>
    <flux:input :value="$value" readonly copyable :aria-label="$label" />
</div>
