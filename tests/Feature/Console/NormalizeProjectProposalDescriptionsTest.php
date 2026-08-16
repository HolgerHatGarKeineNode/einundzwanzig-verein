<?php

use App\Models\ProjectProposal;

/*
 * Der Backfill schreibt in Produktionsdaten, und zwar unumkehrbar. Was hier
 * gepinnt wird, ist deshalb nicht „er normalisiert", sondern die Grenze: er
 * darf Auszeichnung umformen und muss den TEXT unangetastet lassen.
 */

it('macht aus Klartext Absätze, ohne ein Wort zu verlieren', function () {
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = "Erster Absatz mit Inhalt.\n\nZweiter Absatz.";
    $proposal->saveQuietly();

    $this->artisan('project-proposals:normalize-descriptions')->assertSuccessful();

    $after = (string) $proposal->fresh()->description;

    expect($after)->toContain('<p>Erster Absatz mit Inhalt.</p>')
        ->toContain('<p>Zweiter Absatz.</p>');
});

it('überspringt eine Zeile, statt Text zu verlieren, und meldet das als Fehlschlag', function () {
    /*
     * Ein aktives Element trägt hier sichtbaren Text. Der Sanitizer wirft das
     * Element samt Inhalt — richtig für die Sicherheit, aber ein Textverlust,
     * und über einen Textverlust entscheidet kein Stapelverarbeitungslauf
     * allein. Die Zeile bleibt unverändert stehen, der Befehl endet mit
     * Fehlercode.
     */
    $proposal = ProjectProposal::factory()->create();
    $original = '<h1>Titel</h1><script>ein Text, den nur das Script trägt</script>';
    $proposal->description = $original;
    $proposal->saveQuietly();

    $this->artisan('project-proposals:normalize-descriptions')->assertFailed();

    expect((string) $proposal->fresh()->description)->toBe($original);
});

it('rührt eine bereits saubere Beschreibung nicht an', function () {
    // Idempotenz auf der Ebene des Befehls: ein zweiter Lauf darf nichts mehr
    // ändern, sonst schaukelt sich jede wiederholte Ausführung auf.
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = '<p>Sauberer Absatz mit einem <strong>fetten</strong> Wort.</p>';
    $proposal->saveQuietly();

    $this->artisan('project-proposals:normalize-descriptions')->assertSuccessful();

    $afterFirst = (string) $proposal->fresh()->description;

    $this->artisan('project-proposals:normalize-descriptions')->assertSuccessful();

    expect((string) $proposal->fresh()->description)->toBe($afterFirst);
});

it('behält Überschriften, Listen und Links einer echten Beschreibung', function () {
    $proposal = ProjectProposal::factory()->create();
    $proposal->description = '<h2>Ziel</h2><ul><li>Punkt eins</li><li>Punkt zwei</li></ul>'
        .'<p>Mehr auf <a href="https://einundzwanzig.space">der Website</a>.</p>';
    $proposal->saveQuietly();

    $this->artisan('project-proposals:normalize-descriptions')->assertSuccessful();

    $after = (string) $proposal->fresh()->description;

    expect($after)->toContain('<h2>Ziel</h2>')
        ->toContain('<li>Punkt eins</li>')
        ->toContain('Punkt zwei')
        ->toContain('https://einundzwanzig.space');
});
