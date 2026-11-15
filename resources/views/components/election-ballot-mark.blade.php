@props([
    'round' => false,
])

{{-- The box a member puts their cross in. Follows a visually hidden
     `input.peer`; the cross is drawn in when the input is checked (CSS in
     components/custom.css, `.ballot-mark`). --}}
<span aria-hidden="true" @class([
    'ballot-mark relative grid shrink-0 place-items-center border-2 border-ink bg-white',
    'peer-focus-visible:outline-3 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ink',
    'size-7 rounded-full' => $round,
    'size-8 rounded-[3px]' => ! $round,
])>
    <svg viewBox="0 0 24 24" fill="none" class="size-[78%] -rotate-3" stroke="currentColor" stroke-width="3.25" stroke-linecap="round">
        <path pathLength="1" d="M5 5.5 18.5 19" />
        <path pathLength="1" d="M18.5 5 5.5 18.5" />
    </svg>
</span>
