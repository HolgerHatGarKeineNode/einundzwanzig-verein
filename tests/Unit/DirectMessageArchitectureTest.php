<?php

use App\Models\ElectionVote;
use Symfony\Component\Finder\Finder;

/*
 * DoD 464: the direct message code cannot see who voted. Audiences and
 * reminders address the whole roll (E20); a delivery that knew the votes
 * would turn "has not voted yet" into a list one query away.
 *
 * Since b6342de the votes live in `election_votes` (App\Models\ElectionVote).
 * Until 2026-11-16 this file banned the three classes and tables that commit
 * removed, and so guarded nothing: an audience filtered by
 * `whereNotIn('id', ElectionVote::query()->select('einundzwanzig_pleb_id'))`
 * passed the whole suite (jury run 2, K12). Three layers now, so the next
 * rename cannot do that again:
 *
 *   1. The vote model and its table are taken from the model itself, and the
 *      guard first checks that both still exist. A renamed or removed vote
 *      model fails here, not silently.
 *   2. arch(), one target per DM class: no class dependency on the vote model.
 *   3. An exact inventory of the application classes outside the DM code and
 *      of the tables the DM code names. A new one — the vote model under any
 *      future name included — fails until someone adds it here on purpose,
 *      after checking it against this rule.
 *
 * The DM pages (Livewire, Blade) are held to the vote model, the vote table
 * and the `votes` relation by a text scan; their inventory is not pinned.
 *
 * Behaviour, the layer no rename can dodge:
 * tests/Feature/Services/Dm/DirectMessageAudienceTest.php keeps members who
 * already voted in every audience.
 */

/** Directories whose PHP is the direct message code, relative to the project root. */
const DM_DIRECTORIES = ['app/Services/Dm', 'app/Jobs/Dm', 'app/Console/Commands/Dm'];

/** File patterns of DM models and enums, relative to the project root. */
const DM_FILE_PATTERNS = ['app/Models/Dm*.php', 'app/Enums/Dm*.php'];

/** The DM pages: the member inbox and the board's message centre. */
const DM_PAGES = ['resources/views/livewire/association/messages.blade.php', 'resources/views/livewire/association/messages'];

/**
 * Application classes outside the DM code that the DM code may name. Every
 * entry was checked against DoD 464 on 2026-11-16. Add one only after the
 * same check: does it let the DM code learn who voted?
 */
const DM_ALLOWED_APP_CLASSES = [
    'App\Enums\ElectionCandidateStatus',
    'App\Enums\ElectionFeeRule',
    'App\Models\EinundzwanzigPleb',
    'App\Models\Election',
    'App\Models\ElectionCandidate',
    'App\Models\ElectionRollEntry',
    'App\Services\Election\ElectoralRoll',
    'App\Services\Nostr\DirectMessageNotBuildable',
    'App\Services\Nostr\DirectMessageSigner',
    'App\Services\Nostr\NotificationKeyUnavailable',
    'App\Services\Nostr\RelayPublishResult',
    'App\Services\Nostr\RelayPublishStatus',
    'App\Services\Nostr\RelayTransport',
    'App\Support\Board',
];

/** Tables other than the DM's own (dm_*) that the DM code may name. Same rule as above. */
const DM_ALLOWED_TABLES = [
    'einundzwanzig_plebs',
    'election_candidates',
    'election_roll_entries',
    'election_rounds',
];

function dmProjectRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Every PHP file of the DM code (not the pages), absolute paths, sorted.
 *
 * @return list<string>
 */
function dmCodeFiles(): array
{
    $root = dmProjectRoot();
    $files = [];

    foreach (Finder::create()->files()->name('*.php')->in(array_map(fn (string $dir): string => "{$root}/{$dir}", DM_DIRECTORIES)) as $file) {
        $files[] = $file->getRealPath();
    }

    foreach (DM_FILE_PATTERNS as $pattern) {
        array_push($files, ...glob("{$root}/{$pattern}"));
    }

    sort($files);

    return array_values(array_unique($files));
}

/**
 * The fully qualified class of a file, from its namespace and file name.
 */
function dmClassOf(string $file): string
{
    preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file), $match);

    return $match[1].'\\'.basename($file, '.php');
}

function dmIsDmClass(string $class): bool
{
    return preg_match('/^App\\\\(Services\\\\Dm|Jobs\\\\Dm|Console\\\\Commands\\\\Dm|Models\\\\Dm|Enums\\\\Dm)(\\\\|[A-Z])/', $class) === 1;
}

/**
 * The App\… names a PHP source refers to: imports, fully qualified names and
 * class names written into strings. Comments do not count; the namespace
 * declaration is not a reference.
 *
 * @return list<string>
 */
function dmAppNamesIn(string $source): array
{
    $names = [];
    $previous = null;

    foreach (PhpToken::tokenize($source) as $token) {
        if ($token->isIgnorable()) {
            continue;
        }

        if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ! $previous?->is(T_NAMESPACE)) {
            $names[] = ltrim($token->text, '\\');
        }

        if ($token->is(T_CONSTANT_ENCAPSED_STRING) && preg_match_all('/\\\\?App(?:\\\\{1,2}[A-Za-z_][A-Za-z0-9_]*)+/', $token->text, $matches) > 0) {
            foreach ($matches[0] as $match) {
                $names[] = ltrim(str_replace('\\\\', '\\', $match), '\\');
            }
        }

        $previous = $token;
    }

    return array_values(array_filter($names, fn (string $name): bool => str_starts_with($name, 'App\\')));
}

/**
 * The string literals of a PHP source.
 *
 * @return list<string>
 */
function dmStringLiteralsIn(string $source): array
{
    return array_values(array_map(
        fn (PhpToken $token): string => $token->text,
        array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])),
    ));
}

/**
 * Every table the migrations create or rename to.
 *
 * @return list<string>
 */
function dmKnownTables(): array
{
    $tables = [];

    foreach (glob(dmProjectRoot().'/database/migrations/*.php') as $migration) {
        $source = (string) file_get_contents($migration);
        preg_match_all("/Schema::create\(\s*'([a-z0-9_]+)'/", $source, $created);
        preg_match_all("/Schema::rename\(\s*'[a-z0-9_]+'\s*,\s*'([a-z0-9_]+)'/", $source, $renamed);
        array_push($tables, ...$created[1], ...$renamed[1]);
    }

    $tables = array_values(array_unique($tables));
    sort($tables);

    return $tables;
}

/**
 * The known tables named in string literals of `$source`.
 *
 * @param  list<string>  $tables
 * @return list<string>
 */
function dmTablesNamedIn(string $source, array $tables): array
{
    $literals = implode("\n", dmStringLiteralsIn($source));

    return array_values(array_filter(
        $tables,
        fn (string $table): bool => preg_match('/(?<![a-z0-9_])'.preg_quote($table, '/').'(?![a-z0-9_])/', $literals) === 1,
    ));
}

/**
 * What in a DM page reads votes: the vote model, the vote table, or the
 * `votes` relation of a round.
 *
 * @return list<string>
 */
function dmVoteReadsIn(string $source, string $voteClass, string $voteTable): array
{
    $reads = [];
    $short = class_basename($voteClass);

    if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($short, '/').'(?![A-Za-z0-9_])/', $source) === 1) {
        $reads[] = "names {$short}";
    }

    if (preg_match('/(?<![a-z0-9_])'.preg_quote($voteTable, '/').'(?![a-z0-9_])/', $source) === 1) {
        $reads[] = "names {$voteTable}";
    }

    if (preg_match('/->votes\b/', $source) === 1) {
        $reads[] = 'reads a votes relation';
    }

    return $reads;
}

/*
 * The vote model as the application defines it today. If this fails, the
 * vote model was renamed or removed: point the import at the top of this
 * file at its successor before anything else, or this file guards a name
 * that no longer exists.
 */
it('takes the banned vote model and table from the application, and both exist', function () {
    $voteTable = (new ElectionVote)->getTable();

    expect(class_exists(ElectionVote::class))->toBeTrue()
        ->and($voteTable)->toBe('election_votes')
        ->and(dmKnownTables())->toContain($voteTable);
});

foreach (dmCodeFiles() as $dmFile) {
    $dmClass = dmClassOf($dmFile);

    arch("{$dmClass} does not use the election vote model")
        ->expect($dmClass)
        ->not->toUse(ElectionVote::class);
}

it('names exactly the allowed application classes outside the direct message code', function () {
    $files = dmCodeFiles();
    $named = [];

    foreach ($files as $file) {
        foreach (dmAppNamesIn((string) file_get_contents($file)) as $name) {
            if (! dmIsDmClass($name)) {
                $named[$name] = true;
            }
        }
    }

    $named = array_keys($named);
    sort($named);

    // 23 files on 2026-11-16; the floor shows the scan read the real code.
    expect(count($files))->toBeGreaterThanOrEqual(20)
        ->and($named)->not->toContain(ElectionVote::class)
        ->and($named)->toBe(DM_ALLOWED_APP_CLASSES);
});

it('names exactly the allowed tables in the direct message code, and never the vote table', function () {
    $tables = dmKnownTables();
    $named = [];

    foreach (dmCodeFiles() as $file) {
        foreach (dmTablesNamedIn((string) file_get_contents($file), $tables) as $table) {
            if (! str_starts_with($table, 'dm_')) {
                $named[$table] = true;
            }
        }
    }

    $named = array_keys($named);
    sort($named);

    expect(count($tables))->toBeGreaterThan(40)
        ->and($named)->not->toContain((new ElectionVote)->getTable())
        ->and($named)->toBe(DM_ALLOWED_TABLES);
});

it('lets no direct message page read votes', function () {
    $root = dmProjectRoot();
    $voteTable = (new ElectionVote)->getTable();
    $pages = [];
    $reads = [];

    foreach (DM_PAGES as $page) {
        $path = "{$root}/{$page}";
        array_push($pages, ...(is_dir($path) ? array_map(fn ($file): string => $file->getRealPath(), iterator_to_array(Finder::create()->files()->name('*.php')->in($path), false)) : [$path]));
    }

    foreach ($pages as $page) {
        if (($found = dmVoteReadsIn((string) file_get_contents($page), ElectionVote::class, $voteTable)) !== []) {
            $reads[basename($page)] = $found;
        }
    }

    // The inbox and four message centre files on 2026-11-16.
    expect(count($pages))->toBeGreaterThanOrEqual(5)
        ->and($reads)->toBe([]);
});

/*
 * Negative controls for the scanners: each must see a plant, or "nothing
 * found" above could also mean "nothing looked".
 */
it('sees the vote model however the DM code names it', function (string $source) {
    expect(dmAppNamesIn($source))->toContain(ElectionVote::class);
})->with([
    'import' => ["<?php\nnamespace App\\Services\\Dm;\nuse App\\Models\\ElectionVote;\n"],
    'fully qualified' => ["<?php\n\\App\\Models\\ElectionVote::query()->select('einundzwanzig_pleb_id');\n"],
    'class name in a string' => ["<?php\napp('App\\\\Models\\\\ElectionVote');\n"],
]);

it('does not count the namespace declaration or a comment as a reference', function () {
    expect(dmAppNamesIn("<?php\nnamespace App\\Services\\Dm;\n// App\\Models\\ElectionVote\n/** @see \\App\\Models\\ElectionVote */\n"))->toBe([]);
});

it('sees the vote table in a raw query and in a qualified column', function (string $source) {
    expect(dmTablesNamedIn($source, dmKnownTables()))->toContain('election_votes');
})->with([
    'table' => ["<?php\nDB::table('election_votes')->pluck('einundzwanzig_pleb_id');\n"],
    'qualified column' => ["<?php\n\$q->whereNotIn('id', fn (\$s) => \$s->select('election_votes.einundzwanzig_pleb_id'));\n"],
]);

it('sees a vote read in a page', function (string $source, array $reads) {
    expect(dmVoteReadsIn($source, ElectionVote::class, 'election_votes'))->toBe($reads);
})->with([
    'model' => ['<?php ElectionVote::query()->count();', ['names ElectionVote']],
    'table' => ["<?php DB::table('election_votes')->count();", ['names election_votes']],
    'relation' => ['<?php $round->votes()->pluck("einundzwanzig_pleb_id");', ['reads a votes relation']],
    'clean' => ['<?php $round->rollEntries()->count();', []],
]);
