<?php

namespace App\Models;

use App\Enums\ProjectProposalStatus;
use App\Support\RichTextSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cookie;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;
use swentel\nostr\Key\Key;

class ProjectProposal extends Model implements HasMedia
{
    use HasFactory;
    use HasSlug;
    use InteractsWithMedia;

    /**
     * Die Beschreibung, wie sie ausgegeben werden darf.
     *
     * DIE SICHERHEITSGRENZE LIEGT HIER, nicht beim Speichern. `description`
     * wird mit `{!! !!}` gerendert — der einen Blade-Konstruktion, die nichts
     * escapt —, und was in der Spalte steht, hat der Antragsteller geschrieben.
     * Vor diesem Accessor lief ein gespeichertes `<script>` bei JEDEM Besucher
     * der öffentlichen Detailseite; nachgemessen, ohne Anmeldung, Status 200.
     *
     * WARUM ZUSÄTZLICH ZUM SANITIZING IM `RichTextMarkdownNormalizer`, und
     * nicht statt seiner: Der Normalizer sieht nur, was nach seiner Einführung
     * geschrieben wurde. Zeilen, die vorher entstanden sind, kämen ungefiltert
     * heraus — ausgerechnet die, die niemand geprüft hat. Dieser Accessor
     * greift für jede Zeile, unabhängig davon, wann und über welchen Weg sie
     * entstand.
     *
     * WER DIESE SPALTE AUSGIBT, NIMMT DIESEN ACCESSOR. `description` direkt in
     * ein `{!! !!}` zu setzen ist der Fehler, den es hier zu verhindern gilt;
     * `ProjectProposalXssTest` prüft deshalb die gerenderte Seite und nicht
     * bloß diese Methode.
     */
    public function safeDescription(): ?string
    {
        return (new RichTextSanitizer)->sanitize($this->description);
    }

    /**
     * Größe des aktuellen Vorstands laut Konfiguration.
     */
    public static function boardSize(): int
    {
        return count(config('einundzwanzig.config.current_board', []));
    }

    /**
     * Stimmen, ab denen ein Antrag als angenommen bzw. abgelehnt gilt: die
     * absolute Mehrheit des Vorstands.
     *
     * Bewusst abgeleitet statt als Konstante — der Vorstand wächst, und eine
     * fest verdrahtete 3 wäre bei sieben Mitgliedern keine Mehrheit mehr,
     * sondern eine Minderheit, die Anträge entscheidet.
     */
    public static function boardVoteThreshold(): int
    {
        return intdiv(static::boardSize(), 2) + 1;
    }

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'support_in_sats',
        'website',
        'contact_via_nostr_dm',
        'contact_alternative',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'integer',
        'einundzwanzig_pleb_id' => 'integer',
        'accepted' => 'boolean',
        'sats_paid' => 'integer',
        'contact_via_nostr_dm' => 'boolean',
        'nostr_group_created_at' => 'datetime',
    ];

    protected static function booted() {}

    /**
     * IDs aller Plebs, die im aktuellen Vorstand sitzen.
     *
     * Die Vorstandszugehörigkeit hängt am npub in der Konfiguration, nicht an einer
     * Spalte. Einmal pro Request aufgelöst, damit die Stimm-Aggregate ohne
     * verschachteltes whereHas auf die Profil-Tabelle auskommen.
     *
     * @return list<int>
     */
    public static function boardPlebIds(): array
    {
        // Bewusst NICHT in einer static-Variablen gemerkt. Eine static überlebt
        // bei langlebigen Workern (Octane, Queue) den Request: wird sie einmal
        // gefüllt, bevor ein Vorstandsmitglied als Pleb-Zeile existiert, zählen
        // dessen Stimmen bis zum Worker-Neustart als null — ausgerechnet bei der
        // Abstimmung über Fördergelder. Die Abfrage ist eine indizierte
        // whereIn-Suche über wenige npubs; der Preis ist die Korrektheit wert.
        return EinundzwanzigPleb::query()
            ->whereIn('npub', config('einundzwanzig.config.current_board', []))
            ->pluck('id')
            ->all();
    }

    /**
     * Hängt die Stimm-Aggregate an, die Karten und Status brauchen — ohne die
     * Stimmen selbst zu laden.
     */
    public function scopeWithVoteAggregates(Builder $query): Builder
    {
        $boardPlebIds = static::boardPlebIds();

        return $query->withCount([
            'votes as board_approvals_count' => fn (Builder $q) => $q
                ->where('value', true)
                ->whereIn('einundzwanzig_pleb_id', $boardPlebIds),
            'votes as board_rejections_count' => fn (Builder $q) => $q
                ->where('value', false)
                ->whereIn('einundzwanzig_pleb_id', $boardPlebIds),
            'votes as supporters_count' => fn (Builder $q) => $q->where('value', true),
        ]);
    }

    /**
     * Markiert je Antrag, ob der angegebene Pleb bereits abgestimmt hat — als
     * Unterabfrage statt als geladene Relation, damit die Übersicht keine
     * einzige Vote-Zeile anfassen muss.
     */
    public function scopeWithOwnVote(Builder $query, ?int $plebId): Builder
    {
        if ($plebId === null) {
            return $query;
        }

        return $query->withExists([
            'votes as has_own_vote' => fn (Builder $q) => $q->where('einundzwanzig_pleb_id', $plebId),
        ]);
    }

    /**
     * Schränkt auf einen abgeleiteten Status ein. 'all' lässt die Query unberührt.
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        // 'all' und 'supported' entscheiden sich allein an sats_paid. Die
        // Vorstands-IDs werden deshalb erst in den Zweigen aufgelöst, die sie
        // wirklich brauchen — sonst kostet jeder Aufruf eine Abfrage umsonst.
        if (! in_array($status, [
            ProjectProposalStatus::Rejected->value,
            ProjectProposalStatus::Accepted->value,
            ProjectProposalStatus::InVoting->value,
        ], true)) {
            return $status === ProjectProposalStatus::Supported->value
                ? $query->where('sats_paid', '>', 0)
                : $query;
        }

        $boardPlebIds = static::boardPlebIds();
        $threshold = static::boardVoteThreshold();

        $boardVotes = fn (bool $value) => fn (Builder $q) => $q
            ->where('value', $value)
            ->whereIn('einundzwanzig_pleb_id', $boardPlebIds);

        return match ($status) {
            ProjectProposalStatus::Rejected->value => $query
                ->where(fn (Builder $q) => $q->whereNull('sats_paid')->orWhere('sats_paid', '<=', 0))
                ->whereHas('votes', $boardVotes(false), '>=', $threshold),
            ProjectProposalStatus::Accepted->value => $query
                ->where(fn (Builder $q) => $q->whereNull('sats_paid')->orWhere('sats_paid', '<=', 0))
                ->whereHas('votes', $boardVotes(true), '>=', $threshold)
                ->whereHas('votes', $boardVotes(false), '<', $threshold),
            ProjectProposalStatus::InVoting->value => $query
                ->where(fn (Builder $q) => $q->whereNull('sats_paid')->orWhere('sats_paid', '<=', 0))
                ->whereHas('votes', $boardVotes(true), '<', $threshold)
                ->whereHas('votes', $boardVotes(false), '<', $threshold),
            default => $query,
        };
    }

    /**
     * Anträge, die noch in Abstimmung sind und bei denen der angegebene Pleb
     * seine Stimme noch nicht abgegeben hat — die Arbeitsliste eines
     * Vorstandsmitglieds. Beschlossene oder ausgezahlte Anträge fallen raus:
     * dort ändert eine weitere Stimme nichts mehr.
     */
    public function scopeAwaitingVoteFrom(Builder $query, int $plebId): Builder
    {
        return $query
            ->withStatus(ProjectProposalStatus::InVoting->value)
            ->whereDoesntHave('votes', fn (Builder $q) => $q->where('einundzwanzig_pleb_id', $plebId));
    }

    /**
     * Volltextsuche über Name, Beschreibung und den Namen des Einreichers.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->whereLike('name', '%'.$term.'%')
                ->orWhereLike('description', '%'.$term.'%')
                ->orWhereHas('einundzwanzigPleb.profile', fn (Builder $p) => $p->whereLike('name', '%'.$term.'%'));
        });
    }

    /**
     * Sats, die die Oberfläche anzeigt: die erfasste Auszahlung, sobald eine
     * vorliegt, sonst die beantragte Fördersumme.
     *
     * Die Übersichtskarte darf nicht die Beantragung zeigen, wenn der Verein
     * einen anderen Betrag ausgezahlt hat — sonst bleibt eine Korrektur nur
     * auf der Detailseite sichtbar.
     */
    public function displayedSats(): int
    {
        if ($this->sats_paid > 0) {
            return (int) $this->sats_paid;
        }

        return (int) $this->support_in_sats;
    }

    /**
     * Der abgeleitete Status. Setzt geladene Aggregate voraus (scopeWithVoteAggregates);
     * fällt sonst auf die geladenen Stimmen zurück.
     */
    public function status(): ProjectProposalStatus
    {
        if ($this->sats_paid > 0) {
            return ProjectProposalStatus::Supported;
        }

        if ($this->boardRejections() >= static::boardVoteThreshold()) {
            return ProjectProposalStatus::Rejected;
        }

        if ($this->boardApprovals() >= static::boardVoteThreshold()) {
            return ProjectProposalStatus::Accepted;
        }

        return ProjectProposalStatus::InVoting;
    }

    public function boardApprovals(): int
    {
        return (int) ($this->board_approvals_count ?? $this->countBoardVotes(true));
    }

    public function boardRejections(): int
    {
        return (int) ($this->board_rejections_count ?? $this->countBoardVotes(false));
    }

    public function supporters(): int
    {
        return (int) ($this->supporters_count ?? $this->votes->where('value', true)->count());
    }

    protected function countBoardVotes(bool $value): int
    {
        $boardPlebIds = static::boardPlebIds();

        return $this->votes
            ->where('value', $value)
            ->whereIn('einundzwanzig_pleb_id', $boardPlebIds)
            ->count();
    }

    /**
     * Die NIP-29-Raum-ID des privaten Chatraums zu diesem Antrag.
     *
     * Bewusst aus der ID abgeleitet und nicht aus dem Slug: Der Slug hängt am
     * Namen (HasSlug), und ein umbenannter Antrag bekäme sonst eine zweite
     * Raum-ID — der bestehende Raum mitsamt Verlauf würde zum Waisen, während
     * die Oberfläche einen leeren neuen Raum anböte.
     *
     * Das Präfix trennt Antragsräume von den Meetup-Räumen desselben Relays,
     * die nach demselben Muster mit "m" gebildet werden.
     */
    public function nostrGroupId(): string
    {
        return 'p'.substr(hash('sha256', (string) $this->id), 0, 12);
    }

    /**
     * Wurde der Chatraum bereits angelegt?
     *
     * Beantwortet aus der eigenen Datenbank, nicht durch eine Anfrage an den
     * Relay: Der Gruppen-Relay verlangt NIP-42-AUTH schon zum Lesen, eine
     * keylose Prüfung meldete also immer "nicht vorhanden" und legte bei jedem
     * Aufruf einen weiteren Raum an.
     */
    public function hasNostrGroup(): bool
    {
        return filled($this->nostr_group_h);
    }

    /**
     * Die hex-Pubkeys des aktuellen Vorstands.
     *
     * Quelle ist ausschliesslich das Config-Array — dieselbe Wahrheitsquelle,
     * aus der sich auch boardSize() und die Stimm-Mehrheit ableiten. Der
     * hex-Pubkey wird aus dem npub gerechnet (bech32) und NICHT aus der
     * Pleb-Tabelle gelesen: Ein Vorstandsmitglied, das noch keinen
     * Pleb-Datensatz hat, gehört trotzdem in jeden Antragsraum. Ein Lookup
     * würde es still auslassen — es bekäme die Beratung über einen
     * Förderantrag nicht mit und niemand würde die Lücke bemerken.
     *
     * @return list<string>
     */
    public static function boardPubkeys(): array
    {
        $key = new Key;

        return collect(config('einundzwanzig.config.current_board', []))
            ->map(function (string $npub) use ($key): ?string {
                try {
                    return $key->convertToHex($npub);
                } catch (\Throwable) {
                    // Ein npub, der sich nicht dekodieren laesst, ist ein
                    // Konfigurationsfehler. Er darf die Raumanlage fuer die
                    // uebrigen Mitglieder nicht verhindern.
                    return null;
                }
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Die hex-Pubkeys aller Personen, die in den Chatraum gehören: der aktuelle
     * Vorstand und der Antragsteller.
     *
     * Hex, nicht npub — NIP-29-Events (kind 9000) tragen den rohen Pubkey.
     *
     * @return list<string>
     */
    public function nostrGroupMemberPubkeys(): array
    {
        return collect(static::boardPubkeys())
            ->push($this->einundzwanzigPleb?->pubkey)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * npubs aus der Vorstands-Konfiguration, die sich nicht dekodieren lassen.
     *
     * Erwartungswert ist eine leere Liste. Ist sie es nicht, stimmt die
     * Konfiguration nicht — und der betroffene Vorstand fehlt in jedem Raum.
     * Die Oberfläche meldet das, statt es zu verschlucken.
     *
     * @return list<string>
     */
    public static function boardNpubsUndecodable(): array
    {
        $key = new Key;

        return collect(config('einundzwanzig.config.current_board', []))
            ->reject(function (string $npub) use ($key): bool {
                try {
                    return (bool) $key->convertToHex($npub);
                } catch (\Throwable) {
                    return false;
                }
            })
            ->values()
            ->all();
    }

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()
            ->generateSlugsFrom(['name'])
            ->saveSlugsTo('slug')
            ->usingLanguage(Cookie::get('lang', config('app.locale')));
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion('preview')
            ->fit(Fit::Crop, 300, 300)
            ->nonQueued();
        $this
            ->addMediaConversion('thumb')
            ->fit(Fit::Crop, 130, 130)
            ->width(130)
            ->height(130);
    }

    public function registerMediaCollections(): void
    {
        $this
            ->addMediaCollection('main')
            ->singleFile()
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
            ])
            ->useDisk('private')
            ->useFallbackUrl(asset('einundzwanzig-alpha.jpg'));
    }

    public function getSignedMediaUrl(string $collection = 'main', int $expireMinutes = 60, ?string $conversion = null): string
    {
        $media = $this->getFirstMedia($collection);
        if (! $media) {
            return asset('einundzwanzig-alpha.jpg');
        }

        $parameters = ['media' => $media];

        if ($conversion && $media->hasGeneratedConversion($conversion)) {
            $parameters['conversion'] = $conversion;
        }

        return url()->temporarySignedRoute('media.signed', now()->addMinutes($expireMinutes), $parameters);
    }

    public function einundzwanzigPleb(): BelongsTo
    {
        return $this->belongsTo(EinundzwanzigPleb::class);
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /**
     * Nutzt das Aggregat aus scopeWithOwnVote(), wenn es geladen ist, und fällt
     * sonst auf die geladenen Stimmen zurück.
     */
    public function hasVoteFrom(EinundzwanzigPleb $pleb): bool
    {
        if ($this->has_own_vote !== null) {
            return (bool) $this->has_own_vote;
        }

        return $this->votes->contains('einundzwanzig_pleb_id', $pleb->id);
    }
}
