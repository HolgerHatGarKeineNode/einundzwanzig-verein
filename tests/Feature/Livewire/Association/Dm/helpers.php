<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../../../Feature/Services/Election/helpers.php';

/*
 * Shared by the tests of the board's message centre and its tabs: each tab
 * is a Livewire endpoint of its own, so each must refuse a member who is not,
 * or no longer, on the board.
 */

function messageCentre(): Testable
{
    return Livewire::test('association.messages.admin');
}

/** The "Neue Nachricht" tab, as the page mounts it. */
function messageCompose(): Testable
{
    return Livewire::test('association.messages.admin-compose');
}

/** The "Verlauf" tab, as the page mounts it, with the run it opens. */
function messageHistory(?int $openRunId = null): Testable
{
    return Livewire::test('association.messages.admin-history', ['openRunId' => $openRunId]);
}

/** The "Zeitplan" tab, as the page mounts it. */
function messageSchedule(): Testable
{
    return Livewire::test('association.messages.admin-schedule');
}

/**
 * A board of one, logged in, plus $members further active members.
 *
 * @return array{0: EinundzwanzigPleb, 1: list<EinundzwanzigPleb>}
 */
function messageCentreBoard(int $members = 0): array
{
    [$board] = electionBoard(1);
    $others = [];

    for ($i = 0; $i < $members; $i++) {
        $others[] = EinundzwanzigPleb::factory()->active()->create();
    }

    NostrAuth::login($board->pubkey);

    return [$board, $others];
}

/**
 * Every public method the component declares itself, read off the component
 * so an action added later is covered the day it is written, without the
 * update hooks and the given helpers that only format what is already there.
 * Each one is called by a session that left the board after the page was
 * rendered and must answer 403; so must every update hook in `$updates`.
 *
 * @param  array<string, mixed>  $arguments  a test value per parameter name
 * @param  list<string>  $pure  public helpers that read nothing
 * @param  array<string, mixed>  $updates  property => value set from the browser
 * @return list<string> the methods that were called
 */
function expectEveryMessageMethodBoardOnly(Closure $mount, EinundzwanzigPleb $board, EinundzwanzigPleb $member, array $arguments, array $pure = [], array $updates = []): array
{
    NostrAuth::login($board->pubkey);
    $instance = $mount()->instance();
    $methods = collect((new ReflectionObject($instance))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isStatic())
        ->filter(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === $instance::class)
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->reject(fn (string $name) => $name === 'mount' || str_starts_with($name, 'updated') || in_array($name, $pure, true))
        ->sort()
        ->values()
        ->all();

    foreach ($methods as $method) {
        NostrAuth::login($board->pubkey);
        $page = $mount();
        NostrAuth::login($member->pubkey);

        $reflection = new ReflectionMethod($page->instance(), $method);

        if ($reflection->getAttributes(Computed::class) !== []) {
            unset($page->instance()->{$method});

            expect(fn () => $page->instance()->{$method})->toThrow(HttpException::class);

            continue;
        }

        $values = collect($reflection->getParameters())
            ->reject(fn (ReflectionParameter $parameter) => $parameter->getType() instanceof ReflectionNamedType && ! $parameter->getType()->isBuiltin())
            ->map(fn (ReflectionParameter $parameter) => array_key_exists($parameter->getName(), $arguments)
                ? $arguments[$parameter->getName()]
                : throw new LogicException("No test argument for \${$parameter->getName()} of {$method}()."))
            ->values()
            ->all();

        $page->call($method, ...$values)->assertForbidden();
    }

    foreach ($updates as $property => $value) {
        NostrAuth::login($board->pubkey);
        $page = $mount();
        NostrAuth::login($member->pubkey);

        $page->set($property, $value)->assertForbidden();
    }

    return $methods;
}
