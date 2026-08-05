<?php

declare(strict_types=1);

use Pest\Rector\Rules\SimplifyToBeTruthyFalsyRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/tests',
    ])
    ->withSets([
        __DIR__.'/vendor/pestphp/pest-plugin-rector/config/sets/coding-style.php',
    ])
    ->withSkip([
        // `no_email` hat in App\Models\EinundzwanzigPleb keinen Bool-Cast (die
        // casts()-Methode kennt nur `association_status`) und kommt daher als
        // 1/0 aus der Datenbank. Der explizite (bool)-Cast im Test ist deshalb
        // notwendig; toBeTruthy() wäre die schwächere Prüfung. Siehe
        // tests/Feature/Livewire/Association/ProfileTest.php:176.
        SimplifyToBeTruthyFalsyRector::class,
    ]);
