<?php

namespace App\Services\Dm;

/**
 * The answer of DirectMessageDryRun.
 */
final readonly class DirectMessageDryRunResult
{
    /**
     * @param  int  $count  members in the audience now
     * @param  string  $description  the audience as a German sentence
     * @param  list<string>  $problems  why the text cannot be sent; empty when it can
     * @param  list<array{recipient_id: int, text: string}>  $previews  the text as a few recipients would get it; empty while there are problems
     * @param  list<int>  $unreachableIds  members without a DM inbox (kind 10050); they see the message in the portal only
     * @param  list<int>  $recipientIds  every member in the audience now, ascending; a start compares its frozen list against it
     */
    public function __construct(
        public int $count,
        public string $description,
        public array $problems,
        public array $previews,
        public array $unreachableIds,
        public array $recipientIds = [],
    ) {}
}
