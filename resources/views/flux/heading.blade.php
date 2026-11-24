@blaze(fold: true)

@props([
    'size' => 'base',
    'accent' => false,
    'level' => null,
])

{{--
    One heading scale for the whole app (P3/D8): the page title is the
    <x-page-header> H1 (30/36px); below it xl = 24px, lg = 20px (section and
    dialog titles, the house H2), the default = 16px, the body size, carried
    by weight instead. Flux' own lg was 16px and its default 14px: a dialog
    title the size of its body text.
--}}

@php
$classes = Flux::classes()
    ->add(match ($accent) {
        true => 'text-orange-primary',
        default => '[:where(&)]:text-text-primary',
    })
    ->add(match ($size) {
        'xl' => 'text-2xl font-bold leading-tight [&:has(+[data-flux-subheading])]:mb-2 [[data-flux-subheading]+&]:mt-2',
        'lg' => 'text-xl font-bold leading-tight [&:has(+[data-flux-subheading])]:mb-2 [[data-flux-subheading]+&]:mt-2',
        default => 'text-base font-semibold leading-snug [&:has(+[data-flux-subheading])]:mb-2 [[data-flux-subheading]+&]:mt-2',
    })
    ;
@endphp

<?php switch ((int) $level): case(1): ?>
        <h1 {{ $attributes->class($classes) }} data-flux-heading>{{ $slot }}</h1>

        @break
    <?php case(2): ?>
        <h2 {{ $attributes->class($classes) }} data-flux-heading>{{ $slot }}</h2>

        @break
    <?php case(3): ?>
        <h3 {{ $attributes->class($classes) }} data-flux-heading>{{ $slot }}</h3>

        @break
    <?php case(4): ?>
        <h4 {{ $attributes->class($classes) }} data-flux-heading>{{ $slot }}</h4>

        @break
    <?php default: ?>
        <div {{ $attributes->class($classes) }} data-flux-heading>{{ $slot }}</div>
<?php endswitch; ?>
