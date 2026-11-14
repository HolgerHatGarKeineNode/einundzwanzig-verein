@props([
    'up' => 0,
    'down' => 0,
    'size' => 'sm',
])

@php
    /*
     * The mood as 21 blocks — the association's number. Filled orange blocks
     * are the share in favour, outlined ones the share against. The blocks
     * are decoration; the two counts next to them carry the information, so
     * colour is never the only signal.
     */
    $blocks = 21;
    $up = (int) $up;
    $down = (int) $down;
    $total = $up + $down;
    $upBlocks = $total === 0 ? 0 : (int) round($blocks * $up / $total);

    // A side with at least one vote always shows at least one block.
    if ($up > 0 && $upBlocks === 0) {
        $upBlocks = 1;
    }
    if ($down > 0 && $upBlocks === $blocks) {
        $upBlocks = $blocks - 1;
    }

    $downBlocks = $total === 0 ? 0 : $blocks - $upBlocks;
    $isLarge = $size === 'lg';
@endphp

<div {{ $attributes->class(['relative flex min-w-0', 'flex-col gap-2' => $isLarge, 'flex-col gap-1.5' => ! $isLarge]) }}>
    <span aria-hidden="true" @class([
        'grid grid-cols-21',
        'gap-1 w-full' => $isLarge,
        'gap-0.5 w-42' => ! $isLarge,
    ])>
        @for ($i = 0; $i < $blocks; $i++)
            <span @class([
                'block rounded-[2px] border transition-colors duration-200 ease-out motion-reduce:transition-none',
                'h-6' => $isLarge,
                'h-3' => ! $isLarge,
                'border-orange-500 bg-orange-500' => $i < $upBlocks,
                'border-neutral-400 bg-transparent' => $i >= $upBlocks && $i < $upBlocks + $downBlocks,
                'border-border-default bg-transparent' => $i >= $upBlocks + $downBlocks,
            ])></span>
        @endfor
    </span>
    <span @class(['text-text-secondary', 'text-sm' => ! $isLarge, 'text-base' => $isLarge])>
        @if ($total === 0)
            {{ __('Noch keine Stimmen') }}<span class="sr-only">: <span data-test="up-count">0</span> {{ __('dafür') }}, <span data-test="down-count">0</span> {{ __('dagegen') }}</span>
        @else
            <span class="font-semibold text-text-primary" data-test="up-count">{{ $up }}</span> {{ __('dafür') }},
            <span class="font-semibold text-text-primary" data-test="down-count">{{ $down }}</span> {{ __('dagegen') }}
        @endif
    </span>
</div>
