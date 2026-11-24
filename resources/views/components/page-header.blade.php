@props([
    'title',
    'lead' => null,
])

{{--
    The one page title of every page (P3/D5), modelled on the election and
    message pages: one H1 (30px, 36px from sm up, bold, line-height 1.25),
    an optional lead in the secondary text colour, and an optional `actions`
    slot that sits beside the title on a wide screen and below it on a phone.
    The default slot takes anything that belongs to the title block itself
    (a switcher, a status line).

    `title` is a string or a named slot; a named slot passes its attributes
    on to the h1, e.g. <x-slot:title data-test="...">.
--}}
@php
    $titleAttributes = $title instanceof \Illuminate\View\ComponentSlot ? $title->attributes : new \Illuminate\View\ComponentAttributeBag;
@endphp

<header {{ $attributes->class(['mb-8 flex flex-wrap items-end justify-between gap-x-6 gap-y-4']) }}>
    <div class="min-w-0 max-w-2xl">
        <h1 {{ $titleAttributes->class(['hyphens-auto break-words text-3xl font-bold leading-tight text-text-primary sm:text-4xl']) }}>{{ $title }}</h1>
        @if (filled($lead))
            <p class="mt-2 max-w-prose text-base leading-normal text-text-secondary">{{ $lead }}</p>
        @endif
        {{ $slot }}
    </div>
    @isset($actions)
        <div {{ $actions->attributes->class(['flex flex-wrap items-center gap-2']) }}>{{ $actions }}</div>
    @endisset
</header>
