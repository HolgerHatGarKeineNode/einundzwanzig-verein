<?php

use App\Models\EinundzwanzigPleb;
use App\Models\MemberProposal;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Shared by the member proposal tests. Required by each of them rather than
 * living in tests/Pest.php, so every file still runs on its own. The shared
 * datasets are next door in Datasets.php, which Pest loads by itself.
 */

function showProposal(MemberProposal $proposal): Testable
{
    return Livewire::test('association.proposals.show', ['memberProposal' => $proposal]);
}

/**
 * A member who may take part: a membership category and this year's fee.
 *
 * @param  array<string, mixed>  $attributes
 */
function paidMember(array $attributes = []): EinundzwanzigPleb
{
    return EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create($attributes);
}

function boardMember(): EinundzwanzigPleb
{
    return EinundzwanzigPleb::factory()->boardMember()->create();
}
