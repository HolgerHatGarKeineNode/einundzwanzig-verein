WIP

## Lokale Entwicklung am Chat-Package

Der Chat der Projektunterstützungen kommt aus dem Package
[`einundzwanzig/group`](https://github.com/HolgerHatGarKeineNode/einundzwanzig-group-package)
— demselben, das `einundzwanzig-group` verwendet. Damit bleibt die Chat-Logik an
einer Stelle gepflegt.

**Liegt das Repo `einundzwanzig-group` als Nachbarverzeichnis daneben, wird
automatisch dagegen entwickelt** — für Änderungen am Package braucht es dann
weder Commit noch Push noch `composer update`:

```
~/Code/
├── einundzwanzig-verein/                      ← dieses Repo
└── einundzwanzig-group/
    └── packages/einundzwanzig-group/          ← wird automatisch verwendet
```

| Werkzeug | lokal | auf dem Server |
|---|---|---|
| Composer | `path`-Repo mit Symlink | `vcs`-Fallback, greift wenn der Pfad fehlt |
| Vite/npm | Alias auf das Nachbar-Repo | der Pin in `package.json` |

Beim Build meldet Vite, welche Quelle er nimmt:

```
→ @einundzwanzig/group: lokales Nachbar-Repo (nicht der Pin aus package.json)
```

Fehlt das Nachbarverzeichnis, greifen still die gepinnten Versionen — auf dem
Server ist das der Normalfall, es ist also nichts umzustellen.

**Den Server-Zustand lokal nachstellen** (z. B. um vor einem Deploy zu prüfen,
ob der Pin wirklich alles mitbringt):

```bash
GROUP_PACKAGE_LOCAL=0 npm run build
```

### Wenn eine Package-Änderung ausgeliefert werden soll

Lokal wirkt sie sofort, im Deployment aber erst nach diesen Schritten:

1. Im Package-Repo committen und pushen.
2. Hier den Pin in `package.json` auf den neuen Commit setzen.
3. `composer update einundzwanzig/group` — zieht `composer.lock` nach.

Beide Pins (`package.json` und `composer.lock`) müssen auf denselben Commit
zeigen, sonst liefert das Deployment zwei verschiedene Stände aus.

### `package-lock.json` fehlt bewusst

Sie ist derzeit **nicht** im Repo. Die zuletzt eingecheckte Fassung zeigte auf
das lokale Nachbarverzeichnis (`file:../einundzwanzig-group/…`, `"link": true`)
— einen Pfad, den es auf keinem anderen Rechner gibt. Damit war sie nicht bloß
veraltet, sondern irreführend: `npm ci` scheiterte daran mit einer Meldung, die
nach einem Netzwerkproblem aussah, statt nach einer außer Takt geratenen
Lockdatei.

Sie ist **nicht** in `.gitignore`. Das nächste `npm install` legt sie sauber neu
an und sie taucht sichtbar zum Committen auf — bitte dann mit einchecken, damit
`npm ci` wieder greift und Deployments reproduzierbar werden.

Bis dahin trägt das Forge-Kommando den Fall selbst:

```
(npm ci --include=dev || npm install) && npm run build
```

`npm ci` fällt aus, `npm install` löst aus `package.json` auf und holt den Pin.
