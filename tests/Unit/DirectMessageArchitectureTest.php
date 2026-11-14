<?php

use App\Models\ElectionBallotChoice;
use App\Models\ElectionMark;
use App\Models\ElectionReceipt;
use Symfony\Component\Finder\Finder;

/*
 * DoD 464: the direct message code cannot see who voted. Audiences and
 * reminders address the whole roll (E20); a delivery that knew the marks
 * would turn "has not voted yet" into a list one query away.
 *
 * Two layers: arch() catches a class dependency, the text scan catches the
 * table names in a raw query, which arch() does not see.
 */

const DM_NAMESPACES = ['App\Services\Dm', 'App\Jobs\Dm'];

const DM_CLASSES = [
    'App\Models\DmMessage',
    'App\Models\DmRun',
    'App\Models\DmDelivery',
    'App\Models\DmDeliveryRelay',
    'App\Enums\DmMessageType',
    'App\Enums\DmDeliveryStatus',
];

arch('the direct message code uses neither the election marks, the ballots nor the receipts')
    ->expect([...DM_NAMESPACES, ...DM_CLASSES])
    ->not->toUse([ElectionMark::class, ElectionReceipt::class, ElectionBallotChoice::class]);

it('names no election mark, ballot or receipt table in the direct message code', function () {
    $files = Finder::create()->files()->name('*.php')->in([
        __DIR__.'/../../app/Services/Dm',
        __DIR__.'/../../app/Jobs/Dm',
    ]);

    foreach (DM_CLASSES as $class) {
        $files->append([(new ReflectionClass($class))->getFileName()]);
    }

    $scanned = 0;
    $found = [];

    foreach ($files as $file) {
        $scanned++;

        if (preg_match('/election_(marks|receipts|ballot_choices)/', (string) file_get_contents((string) $file)) === 1) {
            $found[] = basename((string) $file);
        }
    }

    // The arch() expectation above passes for a namespace that holds nothing;
    // this count is what shows both layers looked at real files.
    expect($scanned)->toBeGreaterThanOrEqual(count(DM_CLASSES) + 5)
        ->and($found)->toBe([]);
});
