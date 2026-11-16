<?php

use App\Models\ElectionVote;
use Illuminate\Support\Str;
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
 *   4. No model method that reads votes, in the DM code and the DM pages:
 *      `->name`, `name()`, and the name in a string (`whereHas('votes')`,
 *      `with('rounds.votes')`, `withCount('votes')`). Layers 1 to 3 see names
 *      only, and the allowed `Election` reaches the votes through `rounds()`
 *      → `votes()` with neither the vote model nor its table written down
 *      (jury gate j2f, D1: G2b). The names are derived from app/Models: a
 *      method whose body refers to the vote model (any spelling: imported,
 *      aliased, fully qualified, in a string) reads votes, and so does every
 *      method that calls such a method, repeated until nothing new is found.
 *      On 2026-11-16 that is ElectionRound::votes() and
 *      ElectionRound::voteEventIdOf() (jury gate j2f, D2: R7 read the votes
 *      through voteEventIdOf in the fan-out, and only `votes` was derived).
 *
 * What the static layers cannot see: a name built at run time
 * (`$round->{'vo'.'tes'}`, `$round->$relation`), a vote read in an allowed
 * class outside app/Models, or a model method whose read the derivation does
 * not follow: a relation name as the first segment of a string on another
 * model's query (`ElectionRound::query()->whereHas('votes')` inside
 * Election), a model method that reads the vote TABLE by name
 * (`DB::table('election_votes')`, `->from()`, a join) instead of the vote
 * model, or a name resolved through `__get`/`__call`. The
 * DM pages (Livewire, Blade) are held to the vote model, the vote table and
 * the vote readers by a text scan; their inventory is a floor, not a pin.
 * The scanned DM directories are pinned against the directories named `Dm`
 * under app/, so dropping one from DM_DIRECTORIES fails.
 *
 * Behaviour, for the paths it runs (not for every possible path):
 *  - tests/Feature/Services/Dm/DirectMessageAudienceTest.php keeps members
 *    who already voted in every audience query.
 *  - tests/Feature/Services/Dm/DirectMessageDryRunTest.php freezes the same
 *    recipient list whether or not a member voted (dry run, confirmed start,
 *    `dm:send-due`), under Queue::fake(): it does not run the fan-out.
 *  - tests/Feature/Services/Dm/DirectMessageSendPathBallotSecrecyTest.php
 *    runs the confirmed start and `dm:send-due` through the fan-out and every
 *    SendDirectMessage, with only the relay faked, and holds the deliveries
 *    and each delivery's outcome to be the same before and after a member
 *    votes. It sees a vote read however it is written, but only on these two
 *    paths, for the four audiences it runs, and only where the read changes
 *    who gets a delivery or how that delivery ends. It does NOT compare the
 *    text a member receives, and the sync queue ignores `->delay()`, so a read
 *    that changes only the wording or the send time for voters passes it
 *    (jury gate j2f, round 3: O6, O9, O12). A read whose effect needs another
 *    fixture (a second round, a vote in a parent election) passes it too.
 */

/** Directories whose PHP is the direct message code, relative to the project root. */
const DM_DIRECTORIES = ['app/Services/Dm', 'app/Jobs/Dm', 'app/Console/Commands/Dm'];

/**
 * The directories named `Dm` under app/ on 2026-11-16. Every one must be in
 * DM_DIRECTORIES and hold code; a new one fails until it is added to both.
 */
const DM_DIRECTORIES_ON_DISK = ['app/Console/Commands/Dm', 'app/Jobs/Dm', 'app/Services/Dm'];

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

/**
 * A PHP source without its comments.
 */
function dmWithoutComments(string $source): string
{
    return implode('', array_map(
        fn (PhpToken $token): string => $token->text,
        array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->is([T_COMMENT, T_DOC_COMMENT])),
    ));
}

/**
 * The namespace of a source and its top level `use` imports (alias => class).
 *
 * @return array{namespace: string, imports: array<string, string>}
 */
function dmNameContextOf(string $source): array
{
    preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);
    preg_match_all('/^use\s+\\\\?([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $uses, PREG_SET_ORDER);
    $imports = [];

    foreach ($uses as $use) {
        $imports[($use[2] ?? '') !== '' ? $use[2] : class_basename($use[1])] = $use[1];
    }

    return ['namespace' => trim($namespace[1] ?? ''), 'imports' => $imports];
}

/**
 * The class a name in `$context` stands for, the way PHP resolves it.
 *
 * @param  array{namespace: string, imports: array<string, string>}  $context
 */
function dmResolveClassName(string $name, array $context): string
{
    if (str_starts_with($name, '\\')) {
        return ltrim($name, '\\');
    }

    $first = explode('\\', $name)[0];

    if (isset($context['imports'][$first])) {
        return $context['imports'][$first].substr($name, strlen($first));
    }

    return ltrim($context['namespace'].'\\'.$name, '\\');
}

/**
 * Does `$code` (a method body) refer to `$class`: by a name that resolves to
 * it (imported, aliased, relative or fully qualified; `::class`, `::query()`,
 * `new`, `instanceof` alike) or by its name written into a string?
 *
 * @param  array{namespace: string, imports: array<string, string>}  $context
 */
function dmRefersToClass(string $code, string $class, array $context): bool
{
    foreach (PhpToken::tokenize("<?php {$code}") as $token) {
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && dmResolveClassName($token->text, $context) === $class) {
            return true;
        }

        if ($token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) && in_array($class, dmAppNamesIn("<?php {$token->text};"), true)) {
            return true;
        }
    }

    return false;
}

/**
 * The names under which a model method is called from outside: its own name,
 * the scope name of `scopeX()`, and the attribute of an accessor
 * (`getXAttribute()`, or a method returning an Attribute).
 *
 * @return list<string>
 */
function dmCallNamesOf(string $method, string $returnType): array
{
    $names = [$method];

    if (preg_match('/^scope([A-Z][A-Za-z0-9_]*)$/', $method, $scope) === 1) {
        $names[] = lcfirst($scope[1]);
    }

    if (preg_match('/^get([A-Z][A-Za-z0-9_]*)Attribute$/', $method, $accessor) === 1) {
        $names[] = Str::snake($accessor[1]);
    }

    if (preg_match('/(^|\\\\)Attribute$/', $returnType) === 1) {
        $names[] = Str::snake($method);
    }

    return $names;
}

/**
 * The relation paths named in the string literals of `$body`: every literal
 * whose whole text has the form Eloquent takes a relation in (`'votes'`,
 * `'rounds.votes'`, `'votes as n'`, `'votes:id,event_id'`), as the list of
 * its dotted segments. A sentence that happens to contain the word is not a
 * relation path.
 *
 * @return list<list<string>>
 */
function dmRelationPathsIn(string $body): array
{
    $paths = [];

    foreach (dmStringLiteralsIn("<?php {$body}") as $literal) {
        $text = preg_replace('/^[\'"]|[\'"]$/', '', $literal);

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*)(?:\s+as\s+[A-Za-z_][A-Za-z0-9_]*)?(?::[A-Za-z0-9_,]+)?$/', (string) $text, $match) === 1) {
            $paths[] = explode('.', $match[1]);
        }
    }

    return $paths;
}

/**
 * Does `$body`, a method of a model whose own vote readers are called
 * `$own`, call a vote reader? `$this->x`, `self::x`, `static::x` and a
 * relation path starting with `x` count against `$own`: they address this
 * model. `->x` on anything else, and `x` after a dot in a relation path,
 * count against `$anywhere`, the call names of every vote reader of every
 * model.
 *
 * @param  list<string>  $own
 * @param  list<string>  $anywhere
 */
function dmCallsVoteReader(string $body, array $own, array $anywhere): bool
{
    foreach (dmRelationPathsIn($body) as $path) {
        if (in_array($path[0], $own, true) || array_intersect(array_slice($path, 1), $anywhere) !== []) {
            return true;
        }
    }

    foreach (array_unique([...$own, ...$anywhere]) as $name) {
        $word = preg_quote($name, '/');
        $isOwn = in_array($name, $own, true);

        preg_match_all('/(?:(\$this)\s*)?\??->\s*'.$word.'(?![A-Za-z0-9_])/', $body, $calls, PREG_SET_ORDER);

        foreach ($calls as $call) {
            if (($call[1] ?? '') === '' ? in_array($name, $anywhere, true) : $isOwn) {
                return true;
            }
        }

        if ($isOwn && preg_match('/(?<![A-Za-z0-9_])(?:self|static)\s*::\s*'.$word.'(?![A-Za-z0-9_])/', $body) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * The model methods that read votes, `Model::method` => the names they are
 * called by (dmCallNamesOf()), sorted by key: every method
 * whose body refers to `$voteClass`, and then every method that calls one of
 * them, repeated until a pass finds nothing new. `$sources` maps a model's
 * short class name to its source; the vote model itself is left out.
 *
 * @param  array<string, string>  $sources
 * @return array<string, list<string>>
 */
function dmDeriveVoteReaders(array $sources, string $voteClass): array
{
    $models = [];

    foreach ($sources as $model => $source) {
        if ($model === class_basename($voteClass)) {
            continue;
        }

        preg_match_all(
            '/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\((?:[^()]++|\((?:[^()]++)*\))*\)\s*(?::\s*([?A-Za-z0-9_\\\\|]+))?\s*(\{(?:[^{}]++|(?3))*\})/',
            dmWithoutComments($source),
            $methods,
            PREG_SET_ORDER,
        );

        $models[$model] = ['context' => dmNameContextOf($source), 'methods' => []];

        foreach ($methods as $method) {
            $models[$model]['methods'][$method[1]] = ['body' => $method[3], 'names' => dmCallNamesOf($method[1], $method[2] ?? '')];
        }
    }

    $readers = [];

    foreach ($models as $model => $definition) {
        foreach ($definition['methods'] as $method => $parts) {
            if (dmRefersToClass($parts['body'], $voteClass, $definition['context'])) {
                $readers[$model][$method] = $parts['names'];
            }
        }
    }

    do {
        $found = false;
        $anywhere = array_values(array_unique(array_merge([], ...array_map(
            fn (array $methods): array => array_merge([], ...array_values($methods)),
            array_values($readers),
        ))));

        foreach ($models as $model => $definition) {
            $own = array_values(array_unique(array_merge([], ...array_values($readers[$model] ?? []))));

            foreach ($definition['methods'] as $method => $parts) {
                if (! isset($readers[$model][$method]) && dmCallsVoteReader($parts['body'], $own, $anywhere)) {
                    $readers[$model][$method] = $parts['names'];
                    $found = true;
                }
            }
        }
    } while ($found);

    $qualified = [];

    foreach ($readers as $model => $methods) {
        foreach ($methods as $method => $names) {
            $qualified["{$model}::{$method}"] = $names;
        }
    }

    ksort($qualified);

    return $qualified;
}

/**
 * The vote readers of app/Models (dmDeriveVoteReaders()).
 *
 * @return array<string, list<string>>
 */
function dmVoteReaders(string $voteClass): array
{
    return dmDeriveVoteReaders(dmModelSources(), $voteClass);
}

/**
 * Every model of app/Models: short class name => source.
 *
 * @return array<string, string>
 */
function dmModelSources(): array
{
    $sources = [];

    foreach (glob(dmProjectRoot().'/app/Models/*.php') as $model) {
        $sources[basename($model, '.php')] = (string) file_get_contents($model);
    }

    return $sources;
}

/**
 * A model source for the negative controls: `<?php`, the App\Models
 * namespace, then `$lines`, one per line.
 */
function dmPlant(string ...$lines): string
{
    return implode("\n", ['<?php', 'namespace App\Models;', ...$lines]);
}

/**
 * The names under which the DM code could call a vote reader of app/Models:
 * every call name (dmCallNamesOf()) of every reader, sorted.
 *
 * @return list<string>
 */
function dmVoteRelations(string $voteClass): array
{
    $names = array_values(array_unique(array_merge([], ...array_values(dmVoteReaders($voteClass)))));
    sort($names);

    return $names;
}

/**
 * Where a source reads one of `$relations`: `->name` or `?->name` (property,
 * call, higher order proxy), or the name as a word in a string literal, the
 * way Eloquent takes relation names (`whereHas('votes')`, `'rounds.votes'`,
 * `'votes as n'`). Comments do not count. A table name such as
 * `election_votes` is not a relation name: the underscore joins it.
 *
 * @param  list<string>  $relations
 * @return list<string>
 */
function dmVoteRelationReadsIn(string $source, array $relations): array
{
    $code = implode('', array_map(
        fn (PhpToken $token): string => $token->text,
        array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->is([T_COMMENT, T_DOC_COMMENT])),
    ));
    $literals = implode("\n", dmStringLiteralsIn($source));
    $reads = [];

    foreach ($relations as $relation) {
        $word = preg_quote($relation, '/');

        if (preg_match('/->\s*'.$word.'(?![A-Za-z0-9_])/', $code) === 1) {
            $reads[] = "->{$relation}";
        }

        if (preg_match('/(?<![A-Za-z0-9_$\\\\])'.$word.'(?![A-Za-z0-9_])/', $literals) === 1) {
            $reads[] = "'{$relation}'";
        }
    }

    return $reads;
}

/**
 * The directories named `Dm` under app/, relative to the project root, sorted.
 *
 * @return list<string>
 */
function dmDirectoriesOnDisk(): array
{
    $root = dmProjectRoot();
    $directories = [];

    foreach (Finder::create()->directories()->name('Dm')->in("{$root}/app") as $directory) {
        $directories[] = substr($directory->getRealPath(), strlen($root) + 1);
    }

    sort($directories);

    return $directories;
}

/**
 * The directories of dmDirectoriesOnDisk() that dmCodeFiles() read at least
 * one file from, sorted. A directory dropped from DM_DIRECTORIES is missing
 * here.
 *
 * @return list<string>
 */
function dmScannedDirectories(): array
{
    $root = dmProjectRoot();
    $scanned = [];

    foreach (dmCodeFiles() as $file) {
        foreach (dmDirectoriesOnDisk() as $directory) {
            if (str_starts_with($file, "{$root}/{$directory}/")) {
                $scanned[$directory] = true;
            }
        }
    }

    $scanned = array_keys($scanned);
    sort($scanned);

    return $scanned;
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

    // Every DM directory was read, not just "enough files".
    expect(dmScannedDirectories())->toBe(DM_DIRECTORIES_ON_DISK)
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

it('lets no direct message code or page reach the votes through a relation', function () {
    $root = dmProjectRoot();
    $relations = dmVoteRelations(ElectionVote::class);
    $pages = [];
    $reads = [];

    foreach (DM_PAGES as $page) {
        $path = "{$root}/{$page}";
        array_push($pages, ...(is_dir($path) ? array_map(fn ($file): string => $file->getRealPath(), iterator_to_array(Finder::create()->files()->name('*.php')->in($path), false)) : [$path]));
    }

    foreach ([...dmCodeFiles(), ...$pages] as $file) {
        if (($found = dmVoteRelationReadsIn((string) file_get_contents($file), $relations)) !== []) {
            $reads[substr($file, strlen($root) + 1)] = $found;
        }
    }

    // The vote readers are pinned below; every DM directory was read, and
    // the inbox and four message centre files.
    expect($relations)->toContain('votes')
        ->and($relations)->toContain('voteEventIdOf')
        ->and(dmScannedDirectories())->toBe(DM_DIRECTORIES_ON_DISK)
        ->and(count($pages))->toBeGreaterThanOrEqual(5)
        ->and($reads)->toBe([]);
});

/*
 * Jury gate j2f, round 2: 28 scanned files against a floor of 25 left room to
 * drop app/Jobs/Dm from DM_DIRECTORIES unnoticed. The directories are pinned
 * instead: every directory named `Dm` under app/ is scanned and yields code,
 * and every Dm-prefixed class file under app/ is in the scan.
 */
it('scans every directory named Dm under app/ and every Dm-prefixed class file', function () {
    $root = dmProjectRoot();
    $prefixed = array_map(
        fn ($file): string => $file->getRealPath(),
        iterator_to_array(Finder::create()->files()->name('/^Dm[A-Z][A-Za-z0-9]*\.php$/')->in("{$root}/app"), false),
    );

    expect(dmDirectoriesOnDisk())->toBe(DM_DIRECTORIES_ON_DISK)
        ->and(dmScannedDirectories())->toBe(DM_DIRECTORIES_ON_DISK)
        ->and(count($prefixed))->toBeGreaterThanOrEqual(8)
        ->and(array_values(array_diff($prefixed, dmCodeFiles())))->toBe([]);
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

/*
 * The vote readers of app/Models today. R7 (jury gate j2f, round 2) read the
 * votes through voteEventIdOf, which calls `$this->votes()` and names no vote
 * model; it was not derived, so the scan let it through.
 */
it('finds the model methods that read votes, transitively', function () {
    expect(dmVoteReaders(ElectionVote::class))->toBe([
        'ElectionRound::voteEventIdOf' => ['voteEventIdOf'],
        'ElectionRound::votes' => ['votes'],
    ])->and(dmVoteRelations(ElectionVote::class))->toBe(['voteEventIdOf', 'votes']);
});

it('derives a vote reader however a model writes it', function (array $sources, array $readers) {
    expect(array_keys(dmDeriveVoteReaders([...dmModelSources(), ...$sources], ElectionVote::class)))->toBe($readers);
})->with([
    'today' => [[], ['ElectionRound::voteEventIdOf', 'ElectionRound::votes']],
    // R9: the lookbehind before 2026-11-16 skipped `\App\Models\ElectionVote::class`.
    'fully qualified relation' => [
        ['Plant' => dmPlant('class Plant { public function ballots(): HasMany { return $this->hasMany(\App\Models\ElectionVote::class); } }')],
        ['ElectionRound::voteEventIdOf', 'ElectionRound::votes', 'Plant::ballots'],
    ],
    'aliased import' => [
        ['Plant' => dmPlant('use App\Models\ElectionVote as Ballot;', 'class Plant { public function ballots(): HasMany { return $this->hasMany(Ballot::class); } }')],
        ['ElectionRound::voteEventIdOf', 'ElectionRound::votes', 'Plant::ballots'],
    ],
    'class name in a string' => [
        ['Plant' => dmPlant('class Plant { public function ballots() { return $this->hasMany(\'App\Models\ElectionVote\'); } }')],
        ['ElectionRound::voteEventIdOf', 'ElectionRound::votes', 'Plant::ballots'],
    ],
    'a query, not a relation' => [
        ['Plant' => dmPlant('class Plant { public function voters() { return ElectionVote::query()->pluck(\'einundzwanzig_pleb_id\'); } }')],
        ['ElectionRound::voteEventIdOf', 'ElectionRound::votes', 'Plant::voters'],
    ],
    'a wrapper and its caller' => [
        ['ElectionRound' => dmModelSources()['ElectionRound'].'class Extra { public function ballots() { return $this->votes(); } public function hasBallots() { return $this->ballots()->exists(); } }'],
        ['ElectionRound::ballots', 'ElectionRound::hasBallots', 'ElectionRound::voteEventIdOf', 'ElectionRound::votes'],
    ],
    'another model through a reader' => [
        ['Election' => dmPlant('class Election { public function rounds() { return $this->hasMany(ElectionRound::class); } public function hasVoted($pleb): bool { return $this->rounds->contains(fn ($round) => $round->voteEventIdOf($pleb) !== null); } }')],
        ['Election::hasVoted', 'ElectionRound::voteEventIdOf', 'ElectionRound::votes'],
    ],
    'a dotted relation path' => [
        ['Election' => dmPlant('class Election { public function withBallots() { return $this->load(\'rounds.votes\'); } }')],
        ['Election::withBallots', 'ElectionRound::voteEventIdOf', 'ElectionRound::votes'],
    ],
    'an own relation in a string' => [
        ['ElectionRound' => dmModelSources()['ElectionRound'].'class Extra { public function scopeBallotsCounted($query) { return $query->withCount(\'votes as ballots\'); } }'],
        ['ElectionRound::scopeBallotsCounted', 'ElectionRound::voteEventIdOf', 'ElectionRound::votes'],
    ],
    'static' => [
        ['ElectionRound' => dmModelSources()['ElectionRound'].'class Extra { public static function allBallots() { return static::votes(); } }'],
        ['ElectionRound::allBallots', 'ElectionRound::voteEventIdOf', 'ElectionRound::votes'],
    ],
    'clean: another votes relation, comments, prose, another class' => [
        ['Proposal' => dmPlant(
            'class Proposal {',
            '    // ElectionVote::class',
            '    /** \App\Models\ElectionVote::class */',
            '    public function votes() { return $this->hasMany(ProposalVote::class); }',
            '    public function supporters() { return $this->votes->where(\'value\', true)->count(); }',
            '    public function counts($q) { return $q->withCount(\'votes as n\')->whereHas(\'votes\'); }',
            '    public function other() { return ElectionVoteRule::class; }',
            '    public function guard() { throw new RuntimeException(\'votes voteEventIdOf\'); }',
            '}',
        )],
        ['ElectionRound::voteEventIdOf', 'ElectionRound::votes'],
    ],
]);

it('derives the call names of a scope and of an accessor', function (string $source, string $reader, array $names) {
    expect(dmDeriveVoteReaders([...dmModelSources(), 'ElectionRound' => dmModelSources()['ElectionRound'].$source], ElectionVote::class)[$reader] ?? null)->toBe($names);
})->with([
    'local scope' => ['class Extra { public function scopeVoted($query) { return $query->whereHas(\'votes\'); } }', 'ElectionRound::scopeVoted', ['scopeVoted', 'voted']],
    'accessor' => ['class Extra { public function getHasBallotsAttribute(): bool { return $this->votes()->exists(); } }', 'ElectionRound::getHasBallotsAttribute', ['getHasBallotsAttribute', 'has_ballots']],
    'attribute' => ['class Extra { protected function hasBallots(): Attribute { return Attribute::get(fn () => $this->votes()->exists()); } }', 'ElectionRound::hasBallots', ['hasBallots', 'has_ballots']],
]);

it('sees a vote reader called by its derived name', function (string $source, array $reads) {
    expect(dmVoteRelationReadsIn($source, ['ballots', 'voteEventIdOf', 'votes']))->toBe($reads);
})->with([
    'R7: voteEventIdOf in a closure' => ['<?php $run->election?->rounds->contains(fn ($round) => $round->voteEventIdOf($pleb) !== null);', ['->voteEventIdOf']],
    'first-class callable' => ['<?php $read = $round->voteEventIdOf(...);', ['->voteEventIdOf']],
    'callable array' => ["<?php array_map([\$round, 'voteEventIdOf'], \$plebs);", ["'voteEventIdOf'"]],
    'a wrapper' => ['<?php $round->ballots()->where("einundzwanzig_pleb_id", $id)->exists();', ['->ballots']],
]);

it('sees a vote relation read however the DM code writes it', function (string $source, array $reads) {
    expect(dmVoteRelationReadsIn($source, ['votes']))->toBe($reads);
})->with([
    'property through rounds' => ['<?php $election->rounds->flatMap(fn ($round) => $round->votes->pluck("einundzwanzig_pleb_id"));', ['->votes']],
    'call' => ['<?php $round->votes()->pluck("einundzwanzig_pleb_id");', ['->votes']],
    'nullsafe' => ['<?php $round?->votes;', ['->votes']],
    'higher order proxy' => ['<?php $election->rounds->flatMap->votes;', ['->votes']],
    'whereHas' => ["<?php \$election->rounds()->whereHas('votes', fn (\$votes) => \$votes->where('einundzwanzig_pleb_id', 1));", ["'votes'"]],
    'whereDoesntHave' => ["<?php \$q->whereDoesntHave('rounds.votes');", ["'votes'"]],
    'withCount alias' => ["<?php \$election->rounds()->withCount('votes as cast');", ["'votes'"]],
    'eager load' => ["<?php \$election->load(['rounds.votes' => fn (\$q) => \$q]);", ["'votes'"]],
    'has' => ['<?php $election->rounds()->has("votes")->exists();', ["'votes'"]],
    'clean: table name, comment, other relation, variable' => ["<?php\n// \$round->votes\n/** whereHas('votes') */\n\$round->rollEntries()->count();\nDB::table('election_votes');\n\$votes = 1;", []],
]);
