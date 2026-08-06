<?php

namespace App\Console\Commands\Api;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Mint a client key for the /api/v1 surface and print the ready-made env line.
 *
 * It writes nothing — not the .env, not a database row, not a cache entry.
 * Setting the value is a deployment step the operator performs; a command that
 * edited .env behind their back would be indistinguishable from an accident on
 * a production box.
 */
#[Signature('api:client-key {name : Name of the calling application, e.g. einundzwanzig-group}')]
#[Description('Generate a client key for the /api/v1 surface and print the env line to set')]
class GenerateApiClientKey extends Command
{
    /**
     * 32 bytes from the CSPRNG, hex-encoded to 64 characters. Hex rather than
     * base64 so the value survives every .env quoting rule unscathed, and is
     * free of the "," and ":" that carry meaning in API_CLIENT_KEYS.
     */
    private const KEY_BYTES = 32;

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '' || str_contains($name, ',') || str_contains($name, ':')) {
            $this->components->error('The client name must be non-empty and contain neither "," nor ":".');

            return self::FAILURE;
        }

        $key = bin2hex(random_bytes(self::KEY_BYTES));

        $this->newLine();
        $this->components->info("Client key for [{$name}] — shown once, stored nowhere.");
        $this->line('API_CLIENT_KEYS="'.$name.':'.$key.'"');
        $this->newLine();
        $this->components->warn('Append comma-separated to the existing value if other clients are already configured, then run: php artisan config:cache');

        return self::SUCCESS;
    }
}
