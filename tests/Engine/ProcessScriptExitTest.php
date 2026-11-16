<?php

use Illuminate\Support\Facades\Process;

/*
 * The process scripts of the concurrency tests must be able to fail.
 *
 * ConcurrentCastTest and ConcurrentCastingVoteTest check each process's exit
 * code before its output. Jury run 2 measured that a script which boots the
 * Laravel console kernel and then throws exits 0, with the exception rendered
 * on stdout, so that check was vacuous. The scripts now catch every
 * throwable and exit 1 with the exception on stderr; this file holds them to
 * it with a real throw: a round that does not exist (findOrFail).
 */

it('exits non-zero with the exception on stderr when a process script throws', function (string $script, array $arguments) {
    $result = Process::timeout(60)->run([PHP_BINARY, __DIR__."/Support/{$script}", ...$arguments]);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toBe('')
        ->and($result->errorOutput())->toContain('Illuminate\Database\Eloquent\ModelNotFoundException')
        ->and($result->errorOutput())->toContain('[App\Models\ElectionRound]');
})->with([
    'cast-ballot-process.php' => ['cast-ballot-process.php', ['0', '0', '{}', '1']],
    'record-casting-vote-process.php' => ['record-casting-vote-process.php', ['0', '0', '0', '1']],
]);
