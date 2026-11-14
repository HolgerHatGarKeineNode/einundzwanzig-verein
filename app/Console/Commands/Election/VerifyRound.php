<?php

namespace App\Console\Commands\Election;

use App\Exceptions\ElectionRoundStateException;
use App\Models\ElectionManifest;
use App\Models\ElectionRound;
use App\Services\Election\RoundManifest;
use Illuminate\Console\Command;
use JsonException;
use UnexpectedValueException;

/**
 * Recompute the manifest of a sealed round from the ballot tables and
 * compare it with the one written at the close.
 *
 * Exit 0: both texts are identical and the stored text still has its stored
 * hash. Exit 1: something differs — the output names the fields, never their
 * values. Exit 2: there is nothing to compare (unknown round, not sealed, no
 * manifest, annulled).
 *
 * It catches changes made after the close. A change made before it went into
 * the manifest as well and is not visible here.
 */
class VerifyRound extends Command
{
    protected $signature = 'election:verify {round : The id of the round}';

    protected $description = 'Recompute the manifest of a sealed election round and compare it with the stored one';

    public function handle(RoundManifest $manifests): int
    {
        $round = ElectionRound::query()->find($this->argument('round'));

        if ($round === null) {
            $this->error('No such round.');

            return self::INVALID;
        }

        $manifest = ElectionManifest::query()->where('election_round_id', $round->getKey())->first();

        if ($manifest === null) {
            $this->error('This round has no manifest. Only a sealed round has one.');

            return self::INVALID;
        }

        try {
            $stored = json_decode($manifest->body, true, flags: JSON_THROW_ON_ERROR);
            $recomputed = $manifests->body($round, is_array($stored) ? ($stored['audit_head_hash'] ?? null) : null);
        } catch (ElectionRoundStateException) {
            $this->error('The ballots of this round cannot be read: it is not sealed, or it has been annulled.');

            return self::INVALID;
        } catch (JsonException) {
            $this->error('MISMATCH: the stored manifest is not readable JSON.');

            return self::FAILURE;
        } catch (UnexpectedValueException) {
            $this->error('MISMATCH: a ballot row does not fit the rule or the candidates of the round.');

            return self::FAILURE;
        }

        $storedIntact = hash_equals($manifest->hash, RoundManifest::hash($manifest->body));
        $tablesUnchanged = hash_equals($manifest->hash, RoundManifest::hash($recomputed));

        if ($storedIntact && $tablesUnchanged) {
            $this->info('OK round '.$round->getKey().' manifest '.$manifest->hash);

            return self::SUCCESS;
        }

        if (! $storedIntact) {
            $this->error('MISMATCH: the stored manifest text no longer has the stored hash.');
        }

        if (! $tablesUnchanged) {
            $this->error('MISMATCH: the ballot tables no longer give the manifest written at the close.');
        }

        $fresh = json_decode($recomputed, true);
        $differing = is_array($stored)
            ? array_keys(array_filter($fresh, fn (mixed $value, string $key): bool => ($stored[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH))
            : [];

        if ($differing !== []) {
            $this->line('Differing fields: '.implode(', ', $differing));
        }

        return self::FAILURE;
    }
}
