<?php

use App\Enums\MemberProposalState;
use App\Models\EinundzwanzigPleb;

/*
 * Who is refused. `null` is the guest; the other two are logged in. Closures,
 * because a dataset is built before the database exists.
 */
dataset('outsiders', [
    'guest' => [fn () => null],
    'non-member' => [fn () => EinundzwanzigPleb::factory()->withPaidCurrentYear()->create()],
    'member without paid fee' => [fn () => EinundzwanzigPleb::factory()->active()->create()],
]);

/*
 * Everybody who is not on the board — the outsiders plus a member in good
 * standing, who may take part but not decide.
 */
dataset('non-board', [
    'guest' => [fn () => null],
    'non-member' => [fn () => EinundzwanzigPleb::factory()->withPaidCurrentYear()->create()],
    'member without paid fee' => [fn () => EinundzwanzigPleb::factory()->active()->create()],
    'ordinary paying member' => [fn () => EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create()],
]);

dataset('archived proposal states', [
    'adopted' => MemberProposalState::Adopted,
    'rejected' => MemberProposalState::Rejected,
    'done' => MemberProposalState::Done,
]);
