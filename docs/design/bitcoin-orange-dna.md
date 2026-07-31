# Bitcoin-Orange Design-DNA — geteilte Grundlage für zwei EINUNDZWANZIG-Portale

**Status:** Konzept / Entscheidungsgrundlage (KEIN Code-Umbau)
**Gilt für:** `einundzwanzig-app` (offizielles Portal, portal.einundzwanzig.space) · `einundzwanzig-verein` (Vereinsportal)
**Erstellt:** 2026-07-19 · **Autor:** Design-Lead
**Zweck:** Eine abgestimmte Farb-DNA, aus der sich pro Repo ein sauberer Umsetzungsauftrag ableiten lässt. Umsetzung erfolgt getrennt nach Freigabe.

---

## 0. TL;DR — die getroffenen Entscheidungen

1. **Geteilte Marken-Basis = `#F7931A`** (kanonisches Bitcoin-Orange), NICHT `#FF5C00`. Begründung: Marken-Wahrheit + mehr Kontrast-Headroom auf Dunkel (Abschnitt 1).
2. **Orange als Fläche verlangt einen dunklen Vordergrund.** Weiß auf Orange erreicht **kein** AA (2,3–3,1:1). Text/Icon auf einem Orange-Button ist near-black (`#17120A`, ~8,3:1). Das ist der einzige harte Bug im aktuellen `nostr`-Theme (Abschnitt 4).
3. **Orange als Textfarbe auf Dunkel ist erlaubt** — `#F7931A` erreicht 8,6:1 (AAA) auf `#0A0A0B`. Auf Hell muss die Textfarbe auf `orange-800` dunkeln (Abschnitt 4/8).
4. **Geteilt 1:1:** Orange-Skala, Neutral-Rampe, State-Farben, `Inconsolata`, Accent-Logik, Dark-Mode-Mechanik. **Portal-spezifisch:** `nostr` behält Purple (Nostr-Protokoll) + Grün (Relay/Nostr-Feature) als *demoted* Feature-Akzente unter dem Marken-Orange. Das App-Portal braucht sie nicht (Abschnitt 5).
5. **Warm-Kollision beachten:** Wenn die Marke Warm-Orange besitzt, dürfen `warning` und `danger` nicht auch orange sein. `warning` → klares Gelb-Gold, `danger` → sattes Rot, beide immer mit Icon (Farbe nie alleiniger Träger) (Abschnitt 3).

---

## 1. Die Basis-Orange-Entscheidung: `#F7931A` schlägt `#FF5C00`

Zwei Kandidaten stehen im Raum:

| | `#FF5C00` (aktuell `nostr`) | `#F7931A` (offiziell Bitcoin) |
|---|---|---|
| Hue | ~22° (rot-orange, "Warnweste") | ~33° (amber-orange, "Gold") |
| Herkunft | generisches Hot-Orange | Farbe des Bitcoin-Logos, ökosystemweit |
| Kontrast auf `#0A0A0B` | **6,39:1** (AA) | **8,62:1** (AAA) |
| Kontrast auf `#1A1A1D` (elevated) | 5,61:1 (AA) | 7,56:1 (AAA) |
| Wärme | kühl-aggressiv, driftet ins Rote | warm, näher am Gold — freundlicher |

**Entscheidung: `#F7931A`.** Drei Gründe, in dieser Reihenfolge:

1. **Marken-Wahrheit.** EINUNDZWANZIG ist eine Bitcoin-Bildungs-Community. Der Brief heißt "Bitcoin-Orange", nicht "irgendein Orange". `#F7931A` *ist* die Farbe, die Menschen als Bitcoin erkennen. `#FF5C00` ist ein Look-alike, das messbar ins Rote driftet (Hue 22° statt 33°) — es sieht aus wie Bitcoin, ist es aber nicht. Bei einer geteilten DNA über zwei Portale ist die kanonische Farbe der stärkere Anker.
2. **Kontrast-Headroom.** `#F7931A` hält auf *allen drei* dunklen Flächen AAA (8,6 / 7,6 / 8,6:1). Das ist kein Luxus: Orange wird als aktive Nav-Farbe, Link-Farbe und Icon-Tint eingesetzt — je mehr Reserve über 4,5:1, desto robuster gegen dünne Schrift, Anti-Aliasing und elevated Surfaces. `#FF5C00` ist mit 5,6:1 auf `elevated` näher an der Grenze.
3. **Wärme.** `#FF5C00` ist ein hartes Signal-Orange; im Flächeneinsatz (große CTAs, aktive Zeilen) wirkt es schnell schrill. Das amber `#F7931A` trägt Fläche ruhiger.

**`#FF5C00` wird nicht ersatzlos gestrichen** — es ist ein legitimes „Hot-Signal". Wer einen einzelnen Hochspannungs-Akzent braucht (z. B. ein Live-/Neu-Indikator), kann `#FF5C00` als *einzelne* Signalfarbe halten. Es ist aber nicht die Marke und nicht die 500er-Stufe.

> Merke: Die Fußnote unten gilt für **beide** Oranges — auch `#F7931A` erreicht mit weißem Text auf Fläche kein AA. Die Vordergrund-Regel (Abschnitt 4) ist unabhängig von der Basis-Wahl.

---

## 2. Das Warm-Kollisions-Problem (Design-Lead-Hinweis)

Wenn die **Marke** die warme Ecke des Farbkreises besetzt (Orange), entsteht ein Konflikt, den generische Paletten übersehen: `warning` (klassisch Amber/Gelb-Orange) und `danger` (Rot) leben in derselben Nachbarschaft. Drei warme Farben, die „Marke", „Achtung" und „Fehler" bedeuten sollen, sind auf einen Blick nicht auseinanderzuhalten — besonders nicht auf Dunkel, wo Sättigung ohnehin abnimmt.

Auflösung, verbindlich für beide Portale:

- **`warning` weicht ins Gelb-Gold aus** (`#FACC15`, Hue ~48°) — deutlich heller und grüner als das Marken-Orange, dadurch klar unterscheidbar.
- **`danger` bleibt sattes Rot** (`#F87171` auf Dunkel), nicht orange-rot — Distanz zum Marken-Hue maximieren.
- **Farbe ist nie der alleinige Info-Träger** (WCAG 1.4.1). Jeder State trägt zusätzlich ein Icon und/oder Text. Das ist die eigentliche Absicherung — die Hue-Distanz ist die zweite Verteidigungslinie, nicht die erste.

Das ist der nicht-offensichtliche Teil einer Bitcoin-Orange-DNA: Nicht das Orange ist schwer, sondern das, was daneben leben muss.

---

## 3. Accessibility — wo Orange Fläche sein darf und wo nur Akzent

Alle Werte sind Kontrastverhältnisse nach WCAG 2.x (relative Luminanz), berechnet gegen den **echten** Untergrund, nicht gegen Default-Weiß. AA-Grenzen: Text ≥ 4,5:1 (großer Text ≥18pt/14pt-bold ≥ 3:1); UI-Objekte/Rahmen ≥ 3:1 (1.4.11).

### 3.1 Orange auf Dunkel — Textrolle

| Vordergrund | Untergrund | Ratio | Verdikt |
|---|---|---|---|
| `#F7931A` (500) | `#0A0A0B` page | **8,62:1** | AAA — Text jeder Größe |
| `#F7931A` (500) | `#111113` surface | ~8,3:1 | AAA |
| `#F7931A` (500) | `#1A1A1D` elevated | **7,56:1** | AAA |

→ **Orange als Textfarbe (Links, aktive Nav-Labels, Icon-Tint) auf Dunkel: uneingeschränkt AA/AAA.**

### 3.2 Text/Icon AUF einer Orange-Fläche (der kritische Fall)

| Vordergrund | auf `#F7931A` | Ratio | Verdikt |
|---|---|---|---|
| Weiß `#FFFFFF` | Orange-500 | **2,30:1** | **FAIL** — nicht mal 3:1 |
| Weiß auf `#FF5C00` | (Vergleich) | 3,10:1 | nur große/UI, FAIL für normalen Text |
| Near-black `#17120A` | Orange-500 | **8,3:1** | AAA |
| Schwarz `#000000` | Orange-500 | 9,15:1 | AAA |

→ **Regel: Der Vordergrund auf einer Orange-Fläche ist immer near-black.** `--color-accent-foreground` = `#17120A`. Das ist der einzige harte Verstoß im heutigen `nostr`-Theme: `theme.css:50` setzt `--color-accent-foreground: var(--color-text-primary)` = Weiß. Jeder gefüllte Orange-Button mit Label rendert damit bei 3,1:1 (aktuell `#FF5C00`) bzw. würde bei Umstieg auf `#F7931A` auf 2,3:1 fallen — beides unter AA für Button-Text.

### 3.3 Orange als Rahmen / Grafik-Objekt (3:1-Grenze, 1.4.11)

| Stufe | auf `#0A0A0B` | Verdikt |
|---|---|---|
| `orange-500` `#F7931A` | 8,62:1 | weit über 3:1 |
| `orange-700` `#B9640A` | ~4,9:1 | komfortabel |
| `orange-800` `#934E0D` | **3,15:1** | **harter Boden** |
| `orange-900` und dunkler | < 3:1 | FAIL als Rahmen |

→ **Regel: Orange-Rahmen/-Ringe nutzen ≥ `orange-700`. `orange-800` ist der absolute Boden für ein 3:1-UI-Element** auf der Page-Fläche. Nichts Dunkleres als Rahmen einsetzen.

### 3.4 Die Neutral-Text-Rampe (bestätigt, nicht neu erfunden)

Die bestehenden `nostr`-Text-Tokens sind sauber und werden übernommen:

| Token | Wert | auf `#0A0A0B` | Verdikt |
|---|---|---|---|
| text-primary | `#FFFFFF` | 19,8:1 | AAA |
| text-secondary | `#ADADB0` | 8,83:1 | AAA |
| text-tertiary | `#8B8B90` | 5,84:1 | AA |
| text-disabled | `#6B6B70` | 3,74:1 | ausgenommen (disabled), > 3:1 |

Ein Vorbehalt zu `--color-text-muted: #FFFFFFCC` (`theme.css:24`): Das ist Weiß mit 80% Alpha. Über `#0A0A0B` ergibt das effektiv ~`#CCCCCC` → ~13:1, unkritisch. Aber Alpha-Weiß auf *hellerer* elevated-Fläche oder über Bildern wird unberechenbar. **Empfehlung: `text-muted` als deckenden Wert führen** (z. B. `#CFCFD2`), nicht als Alpha — Kontrast gegen wechselnde Untergründe bleibt sonst nicht garantiert. Als Befund gemeldet, nicht hier gefixt.

---

## 4. Vollständige Palette

Werte in Hex (konsistent mit beiden bestehenden `@theme`-Blöcken). Anker `orange-500` zusätzlich in oklch dokumentiert: `oklch(0.70 0.163 47.6)`.

### 4.1 Orange (Marke) — Anker `#F7931A` auf 500

```
orange-50   #FEF3E7   Tint-Background, Hover-Wash auf Dunkel (sehr sparsam)
orange-100  #FDE4C8
orange-200  #FBCB94
orange-300  #F9B25F   großer Display-Akzent
orange-400  #F8A23A   Hover-Aufhellung von 500
orange-500  #F7931A   ← MARKE. accent-fill, Link, aktive Nav
orange-600  #E07F0E   pressed / Gradient-Tiefe
orange-700  #B9640A   Rahmen (Boden für Text auf Hell)
orange-800  #934E0D   3:1-Rahmen-Boden auf Dunkel
orange-900  #78400F
orange-950  #431F05
```

### 4.2 Neutrals (dark-first, um `#0A0A0B`)

```
neutral-50   #FAFAFA
neutral-100  #E4E4E7
neutral-200  #D4D4D8
neutral-300  #ADADB0   text-secondary
neutral-400  #8B8B90   text-tertiary
neutral-500  #6B6B70   text-disabled
neutral-600  #48484E
neutral-700  #2A2A2E   border-default
neutral-800  #1F1F23   border-subtle
neutral-900  #131316
neutral-950  #0A0A0B   bg-page
```

Semantische Flächen (Aliase, nicht jede muss eine Skalenstufe treffen):
`bg-page #0A0A0B` · `bg-surface #111113` · `bg-elevated #1A1A1D`.

### 4.3 State-Farben (auf Dunkel, mit belegtem Kontrast)

```
success  #22C55E   8,68:1 auf #0A0A0B — AAA
warning  #FACC15   ~11:1  — Gelb-Gold, bewusst NICHT orange (Abschnitt 2)
danger   #F87171   ~7:1   — helles Rot, auf Dunkel legibler als #EF4444 (5,25:1)
info     #60A5FA   ~7:1   — kühles Blau, maximale Distanz zur warmen Marke
```

State-Flächen (z. B. gefüllter Danger-Button) folgen derselben Regel wie Orange: near-black oder near-white Vordergrund je nach Helligkeit der Fläche — nie Weiß auf `#FACC15` (das wäre ~1,4:1).

---

## 5. Geteilt vs. portal-spezifisch

| Element | Geteilt 1:1 | Portal-spezifisch |
|---|---|---|
| Orange-Skala 50–950 | ja | — |
| Neutral-Rampe + Surfaces | ja | — |
| State-Farben | ja | — |
| `--font-sans: Inconsolata` | ja | — |
| Accent-Logik (fill = 500, foreground = near-black, content = 500 auf Dunkel) | ja | — |
| Dark-Mode-Mechanik (`.dark` / `:root`, class-based) | ja | — |
| Spacing-/Type-/Motion-Skala (Abschnitt 6) | ja | — |
| **Nostr-Purple `#7c3aed`** | nein | nur `nostr` — Nostr-Protokoll-Identität |
| **Nostr-Grün `#4a7c59`** | nein | nur `nostr` — Relay/Nostr-Feature-Marker |

**Zu Purple & Grün — behalten, aber demoten.** Beide sind *Feature-/Protokoll-Farben*, keine Markenfarben. Sie zeigen „das hier ist Nostr-nativ" (Purple = Nostr-Brand, Grün = Relay/Verbindung). Sie dürfen **nie** mit dem Marken-Orange um dieselbe Rolle konkurrieren (kein Purple-Button neben einem Orange-Button in gleicher Hierarchie). Regel: Orange = Marke/Primäraktion, Purple/Grün = Feature-Signal auf Badges, Icons, kleinen Markern.

Ein Accessibility-Befund dazu: **`#7c3aed` als Textfarbe auf `#0A0A0B` erreicht nur 3,47:1** — das ist unter 4,5:1, also **kein AA für normalen Text**. Purple ist ok als Fläche/Badge oder großer Text (≥3:1), aber für Purple-*Text* muss `nostr` auf eine hellere Stufe (`#a78bfa`, ~ passt AA) wechseln. Gemeldet für den nostr-Umsetzungsauftrag, hier nicht gefixt.

Das App-Portal (heute monochrom) übernimmt Orange + Neutrals + States + Font und lässt Purple/Grün weg. Damit sind beide Portale erkennbar dieselbe Familie, ohne dass das offizielle Portal Nostr-Semantik erbt, die es nicht hat.

---

## 6. Typo, Spacing, Motion — die restliche geteilte DNA

**Typo.** `Inconsolata` bleibt für beide Portale die geteilte Stimme. Das ist eine bewusste, unterscheidende Wahl: eine Monospace als Marken-Schrift evoziert Terminal / Code / Ledger — passend für eine Bitcoin-Community und weit weg vom Default-„freundliche Sans". Modulare Skala (~1,25, Major Third), Body ≥ 16px:

```
12 · 14 · 16 · 18 · 20 · 24 · 30 · 36 · 48 · 60
```
Zeilenhöhe 1.5 für Fließtext, 1.1–1.25 für Headlines. Gewichte 400/700 (Inconsolata liefert beide — genutzt in beiden Repos).

*Ein Flag, keine Vorschrift:* Inconsolata ist eine Monospace, optimiert für Code, nicht für Artikel-Fließtext. Für lange redaktionelle Inhalte (News-Artikel, Doku) leidet die Lesbarkeit über 45–75 Zeichen. Falls ein Portal längere Texte trägt, wäre eine proportionale Body-Companion (max. 2 Familien) zu erwägen — Inconsolata bleibt dann Display/UI/Daten/Zahlen (wo der Ledger-Charakter zählt). Das gehört in den Umsetzungsauftrag, nicht in diese Farb-DNA; hier nur als Hinweis notiert.

**Spacing.** 8pt-Grid, 4pt für Feinabstände. Skala `4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 · 96`. Keine freien Pixelwerte.

**Motion.** 150–300ms, ease-out beim Erscheinen. `prefers-reduced-motion` respektieren. Für Orange als aktiver State: der Übergang erklärt den Zustandswechsel (z. B. Nav-Item wird aktiv), er dekoriert nicht. Elevation sparsam, konsistente Schatten-Stufen; Hover/Pressed/Focus über State-Layer/Opazität, nicht über Ad-hoc-Farben.

**Fokus.** Sichtbarer Fokusring an allen bedienbaren Elementen (2.4.7). Empfehlung: `ring` in `orange-500` mit `ring-offset` in `bg-page` — Kontrast des Rings gegen Fläche ist mit 8,6:1 weit über der 3:1-Grenze für Fokusindikatoren.

---

## 7. Der `@theme`-Block (copy-paste-fertig, kommentiert)

Beide Repos übernehmen den **geteilten** Block. Nur `einundzwanzig-verein` hängt den **portal-spezifischen** Anhang an. Tailwind v4 CSS-first, konsistent mit den bestehenden `theme.css`/`app.css`.

```css
/* =========================================================================
   BITCOIN-ORANGE DESIGN-DNA — geteilt (beide Portale übernehmen 1:1)
   Basis-Orange: #F7931A (kanonisches Bitcoin-Orange). Dark-first.
   ========================================================================= */
@theme {
    /* --- Schrift (geteilte Markenstimme) --- */
    --font-sans: "Inconsolata", monospace;

    /* --- Orange / Marke (Anker: 500 = #F7931A, oklch(0.70 0.163 47.6)) --- */
    --color-orange-50:  #FEF3E7;
    --color-orange-100: #FDE4C8;
    --color-orange-200: #FBCB94;
    --color-orange-300: #F9B25F;
    --color-orange-400: #F8A23A;   /* Hover-Aufhellung von 500 */
    --color-orange-500: #F7931A;   /* MARKE: Fill, Link, aktive Nav */
    --color-orange-600: #E07F0E;   /* pressed / Gradient-Tiefe */
    --color-orange-700: #B9640A;   /* Rahmen; Textfarbe auf HELL */
    --color-orange-800: #934E0D;   /* 3:1-Rahmen-Boden auf DUNKEL */
    --color-orange-900: #78400F;
    --color-orange-950: #431F05;

    /* --- Neutrals (dark-first, um #0A0A0B) --- */
    --color-neutral-50:  #FAFAFA;
    --color-neutral-100: #E4E4E7;
    --color-neutral-200: #D4D4D8;
    --color-neutral-300: #ADADB0;  /* text-secondary  (8,83:1 AAA) */
    --color-neutral-400: #8B8B90;  /* text-tertiary   (5,84:1 AA)  */
    --color-neutral-500: #6B6B70;  /* text-disabled   (3,74:1)     */
    --color-neutral-600: #48484E;
    --color-neutral-700: #2A2A2E;  /* border-default */
    --color-neutral-800: #1F1F23;  /* border-subtle  */
    --color-neutral-900: #131316;
    --color-neutral-950: #0A0A0B;  /* bg-page */

    /* --- Semantische Flächen / Text --- */
    --color-bg-page:      #0A0A0B;
    --color-bg-surface:   #111113;
    --color-bg-elevated:  #1A1A1D;
    --color-border-default: #2A2A2E;
    --color-border-subtle:  #1F1F23;
    --color-text-primary:   #FFFFFF;   /* 19,8:1 */
    --color-text-secondary: #ADADB0;   /*  8,83:1 */
    --color-text-tertiary:  #8B8B90;   /*  5,84:1 */
    --color-text-muted:     #CFCFD2;   /* deckend statt Alpha-Weiss (s. Doc 3.4) */
    --color-text-disabled:  #6B6B70;

    /* --- States (Warm-Kollision aufgelöst: warning=gelb, danger=rot) --- */
    --color-success: #22C55E;   /* 8,68:1 */
    --color-warning: #FACC15;   /* Gelb-Gold, bewusst NICHT orange */
    --color-danger:  #F87171;   /* helles Rot, auf Dunkel legibler als #EF4444 */
    --color-info:    #60A5FA;   /* kuehles Blau, max. Distanz zur Marke */

    /* --- Accent-Logik (Flux) ---
       accent            = Orange-Fill
       accent-content    = Orange als TEXT auf Dunkel (500 = 8,6:1 AAA)
       accent-foreground = Vordergrund AUF Orange-Fill => NEAR-BLACK, nie Weiss
                           (#17120A auf #F7931A = 8,3:1; Weiss waere nur 2,3:1) */
    --color-accent:            var(--color-orange-500);
    --color-accent-content:    var(--color-orange-500);
    --color-accent-foreground: #17120A;
}

/* Dark ist Standard. class-based (.dark) + :root, wie bisher.
   Im Dark-Mode bleibt die Accent-Logik identisch. */
@layer theme {
    .dark,
    :root {
        --color-accent:            var(--color-orange-500);
        --color-accent-content:    var(--color-orange-500);
        --color-accent-foreground: #17120A;
    }
}

/* -------------------------------------------------------------------------
   LIGHT-MODE-Track (optional, falls ein Portal Light unterstützt).
   Kernregel: Orange als TEXT auf Hell erreicht auf 500 nur ~2,2:1 -> FAIL.
   Deshalb flippt accent-content auf orange-800 (6,0:1 auf #FAFAFA).
   ------------------------------------------------------------------------- */
@layer theme {
    :root:not(.dark) {
        --color-bg-page:      #FAFAFA;
        --color-bg-surface:   #FFFFFF;
        --color-bg-elevated:  #FFFFFF;
        --color-text-primary: #131316;
        --color-accent:            var(--color-orange-500); /* Fill bleibt 500 */
        --color-accent-content:    var(--color-orange-800); /* Text dunkelt */
        --color-accent-foreground: #17120A;                 /* auf Fill weiterhin near-black */
    }
}
```

```css
/* =========================================================================
   NUR einundzwanzig-verein — portal-spezifischer Anhang.
   Feature-/Protokoll-Farben, DEMOTED unter dem Marken-Orange.
   Niemals in gleicher Hierarchie neben einem Orange-Primary einsetzen.
   ========================================================================= */
@theme {
    /* Nostr-Protokoll-Identität. Als Text nur die helle Stufe verwenden:
       #7c3aed auf #0A0A0B = 3,47:1 (KEIN AA fuer normalen Text) ->
       Purple-Text nutzt #a78bfa. #7c3aed nur als Fill/Badge/grosser Text. */
    --color-nostr-purple:      #7c3aed;
    --color-nostr-purple-text: #a78bfa;
    /* Relay/Verbindungs-Marker */
    --color-nostr-green:       #4a7c59;
}
```

---

## 8. Offene Empfehlungen & bewusste Widersprüche

Keine dieser Punkte wird hier gefixt — sie gehören in den jeweiligen Umsetzungsauftrag:

1. **`accent-foreground`-Bug (nostr, `theme.css:50`).** Heute Weiß auf Orange → 3,1:1, unter AA für Button-Text. Fix im Umsetzungsauftrag: near-black `#17120A`. Höchste Priorität, weil A11y-Verstoß auf einem sichtbaren Marken-Element.
2. **`text-muted` als Alpha-Weiß (`theme.css:24`).** Gegen wechselnde Untergründe nicht garantierbar. Empfehlung: deckender Wert (`#CFCFD2`).
3. **Nostr-Purple als Text.** Nur 3,47:1 → helle Stufe `#a78bfa` für Text erzwingen.
4. **Light-Mode.** Beide Portale sind dark-first; das App-Portal unterstützt heute Light. Der Light-Track oben ist skizziert, nicht vollständig durchgetestet — vor Aktivierung müssen `text-secondary/tertiary` gegen `#FAFAFA` einzeln belegt werden (auf Hell kehren sich die Kontraste um).
5. **`#FF5C00`.** Nicht verloren, aber degradiert: als *einzelne* Hot-Signal-Farbe zulässig, nicht als Basis. Wenn niemand einen zweiten Signal-Ton braucht, ersatzlos streichen — weniger, aber besser.
6. **Monospace-Body.** Für lange Texte proportionale Companion erwägen (Abschnitt 6). Kein Muss, ein Flag.

---

*Diese DNA ist die Farb-Grundlage. Der breite UI-Audit und der Nav-Umbau (Topbar → Sidebar) des nostr-Portals sind ein separater Folge-Auftrag NACH Freigabe.*
