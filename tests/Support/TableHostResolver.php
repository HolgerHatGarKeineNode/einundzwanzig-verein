<?php

namespace Tests\Support;

use App\Services\Nostr\Relay\HostResolver;

/**
 * DNS from a table: answers what the test wrote down and remembers what it
 * was asked. A name missing from the table does not resolve.
 */
final class TableHostResolver implements HostResolver
{
    /** @var array<int, string> */
    public array $asked = [];

    /**
     * @param  array<string, array<int, string>>  $answers
     */
    public function __construct(private readonly array $answers = []) {}

    public function resolve(string $host): array
    {
        $this->asked[] = $host;

        return $this->answers[$host] ?? [];
    }
}
