@props([
    'label',
    'votes',
    'tone' => 'approve',
])

{{--
    Eine Zeile der Stimmauswertung: Icon, Wort, Zahl.
    Icon UND Wort tragen die Bedeutung — Farbe steht nie allein.

    Bewusst OHNE Avatare/Namen: Das Abstimmungsverhalten Einzelner ist nicht
    öffentlich, die Seite zeigt ausschliesslich das Zahlenergebnis.
--}}
<div class="flex items-center gap-2 py-1.5">
    <flux:icon :name="$tone === 'approve' ? 'check-circle' : 'x-circle'"
               variant="micro"
               @class([
                   'shrink-0',
                   'text-success' => $tone === 'approve',
                   'text-danger' => $tone !== 'approve',
               ])
               aria-hidden="true"/>

    <span class="text-sm text-text-secondary">{{ $label }}</span>

    <span class="flex-1 text-right text-sm font-semibold tabular-nums text-text-primary">
        {{ $votes->count() }}
    </span>
</div>
