@props([
    /** Der wire:model-Pfad des Markdown-Rohtexts, z. B. "form.description". */
    'model',
    'label' => 'Beschreibung',
    'description' => null,
    /** Das gerenderte HTML der Vorschau — vom Aufrufer erzeugt, s. u. */
    'preview' => null,
    'rows' => 12,
])

@php
    // Eine stabile, eindeutige id pro Feld: <markdown-toolbar for="…"> findet
    // sein Textarea über diese id. Aus dem Modellpfad abgeleitet statt zufällig,
    // damit sie über einen Livewire-Roundtrip hinweg dieselbe bleibt — eine
    // zufällige id würde bei jedem Rendern wechseln und die Verbindung kappen.
    $fieldId = 'md-'.md5($model);
@endphp

{{--
    EIN MARKDOWN-FELD, KEIN RICH-TEXT-EDITOR.

    Gespeichert wird der Rohtext, den der Nutzer tippt. Gerendert wird er erst
    bei der Ausgabe, serverseitig, durch denselben CommonMark-Aufbau und
    denselben Sanitizer, die auch die Detailseite benutzt. Deshalb ist die
    Vorschau unten nicht bloß eine Annäherung, sondern byte-genau das, was
    später ausgeliefert wird.

    Der Vorgänger war <flux:editor> (Tiptap). Der speicherte HTML, verstand
    Markdown nur als Tipphilfe und kannte keine Tabellen — weshalb Tabellen als
    roher Text auf der Seite landeten. Was daraus an Reparaturen entstanden war
    (ein Normalisierer, der eingefügtes Markdown nachträglich erkennen musste,
    und ein Paste-Listener im Browser), entfällt mit diesem Feld ersatzlos.
--}}
<flux:field>
    <flux:label>{{ $label }}</flux:label>

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    <markdown-toolbar for="{{ $fieldId }}" class="flex flex-wrap items-center gap-1 mb-2">
        <flux:button as="button" type="button" size="sm" variant="ghost" icon="h1" data-md-button md-header title="Überschrift" aria-label="Überschrift" />
        <flux:button as="button" type="button" size="sm" variant="ghost" icon="bold" data-md-button md-bold title="Fett" aria-label="Fett" />
        <flux:button as="button" type="button" size="sm" variant="ghost" icon="italic" data-md-button md-italic title="Kursiv" aria-label="Kursiv" />
        <flux:button as="button" type="button" size="sm" variant="ghost" icon="strikethrough" data-md-button md-strikethrough title="Durchgestrichen" aria-label="Durchgestrichen" />

        <flux:separator vertical class="mx-1 h-5" />

        <flux:button as="button" type="button" size="sm" variant="ghost" icon="list-bullet" data-md-button md-unordered-list title="Aufzählung" aria-label="Aufzählung" />
        <flux:button as="button" type="button" size="sm" variant="ghost" icon="numbered-list" data-md-button md-ordered-list title="Nummerierte Liste" aria-label="Nummerierte Liste" />
        <flux:button as="button" type="button" size="sm" variant="ghost" icon="chat-bubble-left" data-md-button md-quote title="Zitat" aria-label="Zitat" />

        <flux:separator vertical class="mx-1 h-5" />

        <flux:button as="button" type="button" size="sm" variant="ghost" icon="link" data-md-button md-link title="Link" aria-label="Link" />
        <flux:button as="button" type="button" size="sm" variant="ghost" icon="code-bracket" data-md-button md-code title="Code" aria-label="Code" />
    </markdown-toolbar>

    {{--
        `.live.debounce` und nicht `.blur`: Die Vorschau soll beim Schreiben
        mitlaufen. 500 ms, weil jede Änderung einen Server-Roundtrip samt
        Markdown-Rendern kostet — bei 150 ms tippt man schneller, als der
        Server antwortet.
    --}}
    <flux:textarea
        id="{{ $fieldId }}"
        wire:model.live.debounce.500ms="{{ $model }}"
        rows="{{ $rows }}"
        resize="vertical"
        class="font-mono text-sm"
        placeholder="Markdown ist erlaubt: **fett**, # Überschrift, - Aufzählung, | Tabellen |"
    />

    <flux:error name="{{ $model }}" />

    @if (filled($preview))
        <flux:callout class="mt-4">
            <flux:callout.heading>Vorschau</flux:callout.heading>
            <flux:callout.text>
                {{-- Genau das HTML, das die Detailseite später ausgibt: derselbe
                     Renderer, derselbe Sanitizer. --}}
                <div class="prose dark:prose-invert max-w-none break-words [&_code]:break-all [&_a]:break-all">
                    {!! $preview !!}
                </div>
            </flux:callout.text>
        </flux:callout>
    @endif
</flux:field>
