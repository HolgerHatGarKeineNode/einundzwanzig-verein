@blaze(fold: true, memo: true, unsafe: ['icon:trailing', 'icon:variant'])

@php $iconTrailing ??= $attributes->pluck('icon:trailing'); @endphp
@php $iconVariant ??= $attributes->pluck('icon:variant'); @endphp

@props([
    'iconVariant' => 'micro',
    'iconTrailing' => null,
    'variant' => null,
    'rounded' => null,
    'color' => null,
    'inset' => null,
    'size' => null,
    'icon' => null,
    'label' => null,
])

@php
// Backwards compatibility for 'pill' variant...
if ($variant === 'pill') {
    $rounded = true;
    $variant = null;
}

$insetClasses = Flux::applyInset($inset, top: '-mt-1', right: '-me-2', bottom: '-mb-1', left: '-ms-2');

// When using the outline icon variant, we need to size it down to match the default icon sizes...
$iconClasses = Flux::classes()
    ->add($size === 'sm' ? 'size-3' : ($iconVariant === 'outline' ? 'size-4' : ''));

$classes = Flux::classes()
    ->add('inline-flex items-center font-medium whitespace-nowrap')
    ->add($insetClasses)
    ->add('[print-color-adjust:exact]')
    ->add(match ($size) {
        'lg' => 'text-sm py-1.5 **:data-flux-badge-icon:me-2',
        default => 'text-sm py-1 **:data-flux-badge-icon:me-1.5',
        'sm' => 'text-xs py-1 **:data-flux-badge-icon:me-1',
    })
    ->add(match ($rounded) {
        true => 'rounded-full px-3',
        default => 'rounded-md px-2',
    })
    /**
     * Five badge colours, one per state (P3/D9), from the state tokens in
     * resources/css/theme.css: neutral (default, zinc), success (green),
     * warning (amber, yellow), danger (red), info (blue). Any other colour
     * name falls back to neutral. Dark-only (D11): no light halves.
     */
    ->add(match ($color) {
        default => 'text-text-secondary [&_button]:text-text-secondary! bg-bg-elevated [&:is(button)]:hover:bg-bg-surface',
        'green' => 'text-success [&_button]:text-success! bg-success-surface',
        'amber', 'yellow' => 'text-warning [&_button]:text-warning! bg-warning-surface',
        'red' => 'text-danger [&_button]:text-danger! bg-danger-surface',
        'blue' => 'text-info [&_button]:text-info! bg-info-surface',
    });
@endphp

<flux:button-or-div :attributes="$attributes->class($classes)" data-flux-badge>
    <?php if (is_string($icon) && $icon !== ''): ?>
        <flux:icon :$icon :variant="$iconVariant" :class="$iconClasses" data-flux-badge-icon />
    <?php else: ?>
        {{ $icon }}
    <?php endif; ?>

    {{ $slot->isEmpty() ? $label : $slot }}

    <?php if ($iconTrailing): ?>
        <div class="ps-1 flex items-center" data-flux-badge-icon:trailing>
            <?php if (is_string($iconTrailing)): ?>
                <flux:icon :icon="$iconTrailing" :variant="$iconVariant" :class="$iconClasses" />
            <?php else: ?>
                {{ $iconTrailing }}
            <?php endif; ?>
        </div>
    <?php endif; ?>
</flux:button-or-div>
