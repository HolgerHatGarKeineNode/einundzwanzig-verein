<?php

use App\Exceptions\ElectionCandidateBlockedException;
use App\Exceptions\ElectionRoundStateException;
use App\Models\Profile;
use App\Services\Election\RefusalText;

require_once __DIR__.'/helpers.php';

/*
 * Why the board cannot take a step on a round, in German: the words the run
 * sheet and the live control show for a refusal of the services.
 */

/*
 * Item i: OpenRound (package j2c) refuses a round whose candidate leads an
 * earlier round without a majority while its run-off is still owed. The
 * service is not on this branch, so the exception is built here; the arm
 * must name the person and the way out instead of the generic refusal.
 */
it('names the owed run-off when opening is refused because a candidate leads an earlier round without a majority', function () {
    $round = draftElectionRound(['title' => 'Vorstand', 'position' => 2]);
    $candidate = $round->candidates()->with('pleb')->first();
    Profile::factory()->create(['pubkey' => $candidate->pleb->pubkey, 'name' => 'Anna Albrecht']);

    $text = app(RefusalText::class)->of('open', new ElectionCandidateBlockedException(
        'Candidate stands in an earlier round without a majority, and its second ballot is owed.',
        (int) $candidate->einundzwanzig_pleb_id,
    ));

    expect($text)->toBe('Anna Albrecht gehört zu den Bestplatzierten eines früheren Wahlgangs ohne Mehrheit, und dessen Stichwahl steht noch aus. Legt zuerst die Stichwahl an.')
        ->not->toContain('lässt sich gerade nicht öffnen');
});

it('falls back to a reload hint per action when no arm knows the refusal', function (string $action, string $text) {
    expect(app(RefusalText::class)->of($action, new ElectionRoundStateException('Something nobody wrote an arm for.')))->toBe($text);
})->with([
    'open' => ['open', 'Dieser Wahlgang lässt sich gerade nicht öffnen. Lade die Seite neu, vielleicht hat ihn schon jemand geöffnet.'],
    'close' => ['close', 'Der Wahlgang nimmt keine Stimmen mehr an. Lade die Seite neu, vielleicht hat ihn schon jemand geschlossen.'],
    'strike' => ['strike', 'Streichen geht nur bei einem Wahlgang, der nie geöffnet wurde. Lade die Seite neu, vielleicht hat ihn schon jemand geöffnet oder gestrichen.'],
    'annul' => ['annul', 'Der Wahlgang ist schon annulliert oder noch nicht geöffnet. Lade die Seite neu.'],
]);
