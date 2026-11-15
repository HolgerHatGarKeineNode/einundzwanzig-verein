<?php

declare(strict_types=1);

/**
 * Seeds a SCRATCH database for tests/e2e/ballot-signer-focus.spec.js: one
 * member on the roll of an open live round, factory data only, and prints
 * that member's session cookie as JSON (the spec's E2E_BALLOT_SEED file).
 *
 *   DB_DATABASE=/path/to/scratch.sqlite php artisan migrate:fresh --force   (once per seed: the member key is fixed)
 *   DB_DATABASE=/path/to/scratch.sqlite php tests/e2e/support/seed-ballot-member.php > seed.json
 *   DB_DATABASE=/path/to/scratch.sqlite SESSION_SECURE_COOKIE=false php artisan serve --port=8182
 *
 * Refuses the working database (database/database.sqlite).
 */

use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionRound;
use App\Models\Profile;
use App\Services\Election\OpenRound;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use swentel\nostr\Key\Key;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.'.config('database.default').'.database');

if (config('database.default') !== 'sqlite' || realpath($database) === realpath(database_path('database.sqlite'))) {
    fwrite(STDERR, "Refusing: point DB_DATABASE at a scratch SQLite file, not the working database.\n");
    exit(1);
}

// A throwaway key, known only to this scratch setup.
$secret = 'c3f1d2a4b5e60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
$key = new Key;
$pubkey = $key->getPublicKey($secret);

$member = EinundzwanzigPleb::factory()->active()->withPaidYear((int) now()->year)->create([
    'pubkey' => $pubkey,
    'npub' => $key->convertPublicKeyToBech32($pubkey),
]);

$election = Election::factory()->announced()->create(['name' => 'Vorstandswahl E2E']);
$round = ElectionRound::factory()->create(['election_event_id' => $election->id, 'title' => 'Präsidium']);
foreach (['Alice Ambrosius', 'Bob Brenner'] as $name) {
    $pleb = EinundzwanzigPleb::factory()->active()->create();
    Profile::factory()->create(['pubkey' => $pleb->pubkey, 'name' => $name]);
    ElectionCandidate::factory()->create(['election_round_id' => $round->id, 'einundzwanzig_pleb_id' => $pleb->id]);
}
app(OpenRound::class)->handle($round->fresh());

$session = app('session')->driver();
$session->start();
auth()->guard('nostr')->loginByPubkey($pubkey);
$session->save();

$name = config('session.cookie');
$value = encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId(), false);

echo json_encode(['cookie' => $name, 'value' => $value, 'pubkey' => $pubkey]), "\n";
