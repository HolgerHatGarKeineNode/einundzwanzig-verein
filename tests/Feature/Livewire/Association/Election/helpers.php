<?php

use App\Models\EinundzwanzigPleb;
use App\Support\NostrAuth;
use Livewire\Attributes\Computed;
use Livewire\Features\SupportTesting\Testable;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Shared by the tests of the board's election page and its child
 * components: each of them is a Livewire endpoint of its own, so each must
 * refuse a member who is not, or no longer, on the board.
 */

/**
 * Every public method a component declares itself, read off the component —
 * an action added later is covered the day it is written.
 *
 * @return list<string>
 */
function electionAdminMethods(object $instance): array
{
    return collect((new ReflectionObject($instance))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isStatic())
        ->filter(fn (ReflectionMethod $method) => $method->getDeclaringClass()->getName() === $instance::class)
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->reject(fn (string $name) => $name === 'mount' || str_starts_with($name, 'updated'))
        ->sort()
        ->values()
        ->all();
}

/**
 * Mount the component as `$board`, switch the session to `$member` and call
 * each public method of it — or read it, for a computed property: every one
 * must answer 403. The component must ask again on the request, not trust
 * its mount. Returns the methods, so a test can name the ones it expects.
 *
 * @param  Closure(): Testable  $mount
 * @param  array<string, mixed>  $arguments  a test argument per parameter name
 * @return list<string>
 */
function expectEveryMethodBoardOnly(Closure $mount, EinundzwanzigPleb $board, EinundzwanzigPleb $member, array $arguments): array
{
    NostrAuth::login($board->pubkey);
    $methods = electionAdminMethods($mount()->instance());

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

    return $methods;
}
