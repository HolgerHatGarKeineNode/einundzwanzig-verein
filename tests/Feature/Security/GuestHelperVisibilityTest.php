<?php

use App\Enums\ElectionCandidateStatus;
use App\Models\DmDelivery;
use App\Models\DmMessage;
use App\Models\EinundzwanzigPleb;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Models\Profile;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

/**
 * S4 of the page optimisation plan: every public method of a Livewire
 * component is a callable endpoint, and Livewire binds a model parameter from
 * a client-sent id and sends the return value back. A view helper such as
 * `nameOf(EinundzwanzigPleb $member)` was therefore a name lookup open to
 * guests. Helpers the template calls via `$this->…()` are protected; a client
 * call to them must be refused.
 *
 * Measured at e43fe90: a guest got the name of a rejected candidate from
 * `candidateName` on the election page.
 */
it('refuses a guest calling candidateName for a rejected candidate', function () {
    $round = ElectionRound::factory()->create();
    $pleb = EinundzwanzigPleb::factory()->create();
    Profile::factory()->create(['pubkey' => $pleb->pubkey, 'name' => 'Rejected Candidate Name']);
    $candidate = ElectionCandidate::factory()
        ->status(ElectionCandidateStatus::Rejected)
        ->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $pleb->id]);

    expect(fn () => Livewire::test('association.election.index')->call('candidateName', $round->id, $candidate->id))
        ->toThrow(MethodNotFoundException::class);
});

it('refuses a guest calling runOffOf on the election page', function () {
    $round = ElectionRound::factory()->create();

    expect(fn () => Livewire::test('association.election.index')->call('runOffOf', $round->id))
        ->toThrow(MethodNotFoundException::class);
});

it('refuses a guest calling a model helper of the message centre', function (string $method, Closure $argument) {
    $page = Livewire::test('association.messages.admin');

    expect($page->instance()->isGuest)->toBeTrue();

    expect(fn () => $page->call($method, $argument()))->toThrow(MethodNotFoundException::class);
})->with([
    'nameOf' => ['nameOf', fn () => EinundzwanzigPleb::factory()->create()->id],
    'recipientsOf' => ['recipientsOf', fn () => DmMessage::factory()->create()->freezeRun([EinundzwanzigPleb::factory()->create()])->id],
    'countsOf' => ['countsOf', fn () => DmMessage::factory()->create()->freezeRun([EinundzwanzigPleb::factory()->create()])->id],
    'relaySummary' => ['relaySummary', fn () => DmDelivery::factory()->create()->id],
    'deliveryFailed' => ['deliveryFailed', fn () => DmDelivery::factory()->create()->id],
    'deliveryLabel' => ['deliveryLabel', fn () => DmDelivery::factory()->create()->id],
]);
