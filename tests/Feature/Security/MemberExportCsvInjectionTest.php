<?php

use App\Enums\AssociationStatus;
use App\Models\EinundzwanzigPleb;
use App\Models\Profile;
use App\Support\NostrAuth;
use Livewire\Livewire;

/**
 * S5 of the page optimisation plan: the board's member export carries text a
 * member controls (the Nostr profile name, the email). A cell starting with
 * `=`, `+`, `-`, `@`, a tab or a carriage return is read as a formula by
 * spreadsheet programs; such cells get a leading `'` so they stay text.
 */
const CSV_EXPORT_BOARD_PUBKEY = '0adf67475ccc5ca456fd3022e46f5d526eb0af6284bf85494c0dd7847f3e5033';

/**
 * @return list<list<string>>
 */
function exportedMemberRows(): array
{
    NostrAuth::login(CSV_EXPORT_BOARD_PUBKEY);

    ob_start();
    Livewire::test('association.members.admin')->instance()->exportCsv()->sendContent();
    $content = (string) ob_get_clean();

    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $handle = fopen('php://memory', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $rows = [];
    while (($row = fgetcsv($handle, null, ';', '"', '\\')) !== false) {
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

function memberWithName(string $name, ?string $email = null): EinundzwanzigPleb
{
    $pleb = EinundzwanzigPleb::factory()->create([
        'association_status' => AssociationStatus::ACTIVE,
        'email' => $email,
    ]);
    Profile::factory()->create(['pubkey' => $pleb->pubkey, 'name' => $name]);

    return $pleb;
}

it('neutralises cells a spreadsheet would read as a formula', function (string $name) {
    EinundzwanzigPleb::factory()->create(['pubkey' => CSV_EXPORT_BOARD_PUBKEY]);
    $pleb = memberWithName($name);

    $row = collect(exportedMemberRows())->firstWhere(1, $pleb->npub);

    expect($row)->not->toBeNull()
        ->and($row[0])->toBe("'".$name);
})->with([
    'equals' => ['=HYPERLINK("https://evil.example","klick")'],
    'plus' => ['+1+1'],
    'minus' => ['-2+3'],
    'at' => ['@SUM(A1:A2)'],
    'tab' => ["\t=1+1"],
    'carriage return' => ["\r=1+1"],
]);

it('neutralises a formula in the email cell', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => CSV_EXPORT_BOARD_PUBKEY]);
    $pleb = memberWithName('Alice', '=cmd@example.com');

    $row = collect(exportedMemberRows())->firstWhere(1, $pleb->npub);

    expect($row[2])->toBe("'=cmd@example.com");
});

it('leaves ordinary cells untouched', function () {
    EinundzwanzigPleb::factory()->create(['pubkey' => CSV_EXPORT_BOARD_PUBKEY]);
    $pleb = memberWithName('Alice Example', 'alice@example.com');

    $row = collect(exportedMemberRows())->firstWhere(1, $pleb->npub);

    expect($row[0])->toBe('Alice Example')
        ->and($row[2])->toBe('alice@example.com');
});
