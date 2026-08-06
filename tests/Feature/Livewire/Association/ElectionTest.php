<?php

use App\Models\EinundzwanzigPleb;
use App\Models\Election;
use App\Support\NostrAuth;
use Livewire\Livewire;

it('loads elections on mount', function () {
    $election1 = Election::factory()->create(['year' => 2024]);
    $election2 = Election::factory()->create(['year' => 2025]);

    Livewire::test('association.election.index')
        ->assertSet('elections', function ($elections) {
            return count($elections) >= 2;
        });
});

it('denies access to unauthorized users in election index', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $election = Election::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.election.index', ['election' => $election])
        ->assertSet('isAllowed', false);
});

it('grants access to authorized users in election index', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->create();
    $election = Election::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.election.index', ['election' => $election])
        ->assertSet('isAllowed', true);
});

// Election Admin Tests
it('renders election admin component', function () {
    $election = Election::factory()->create();

    Livewire::test('association.election.admin', ['election' => $election])
        ->assertStatus(200);
});

it('denies access to unauthorized users in election admin', function () {
    $pleb = EinundzwanzigPleb::factory()->create();
    $election = Election::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.election.admin', ['election' => $election])
        ->assertSet('isAllowed', false);
});

it('grants access to authorized users in election admin', function () {
    $pleb = EinundzwanzigPleb::factory()->boardMember()->create();
    $election = Election::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.election.admin', ['election' => $election])
        ->assertSet('isAllowed', true);
});

// Election Show Tests
it('renders election show component', function () {
    $election = Election::factory()->create();

    Livewire::test('association.election.show', ['election' => $election])
        ->assertStatus(200);
});

it('loads election data on mount in show', function () {
    $election = Election::factory()->create();

    Livewire::test('association.election.show', ['election' => $election])
        ->assertSet('election.id', $election->id);
});

it('handles search in election show', function () {
    $election = Election::factory()->create();
    $pleb1 = EinundzwanzigPleb::factory()->active()->create();
    $pleb2 = EinundzwanzigPleb::factory()->boardMember()->create();

    Livewire::test('association.election.show', ['election' => $election])
        ->set('search', $pleb1->pubkey)
        ->assertSet('plebs', function ($plebs) use ($pleb1) {
            return collect($plebs)->contains('pubkey', $pleb1->pubkey);
        });
});

it('can create vote event', function () {
    $election = Election::factory()->create();
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $candidatePubkey = 'test-candidate-pubkey';

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.election.show', ['election' => $election])
        ->call('vote', $candidatePubkey, 'presidency', false)
        ->assertSet('signThisEvent', function ($event) use ($candidatePubkey) {
            return str_contains($event, $candidatePubkey);
        });
});

it('checks election closure status', function () {
    $election = Election::factory()->create([
        'end_time' => now()->subDay(),
    ]);

    Livewire::test('association.election.show', ['election' => $election])
        ->call('checkElection')
        ->assertSet('isNotClosed', false);
});

it('displays log for authorized users', function () {
    $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();
    $election = Election::factory()->create();

    NostrAuth::login($pleb->pubkey);

    Livewire::test('association.election.show', ['election' => $election])
        ->assertSet('isAllowed', true)
        ->assertSet('currentPubkey', $pleb->pubkey);
});

/**
 * Start a throwaway Nostr relay on a free loopback port. It answers `REQ` with
 * an immediate `EOSE` and records every `EVENT` it receives, acknowledging it
 * with a NIP-20 `OK`.
 *
 * This does NOT violate the "no test opens a real connection to the outside"
 * rule that keeps NOSTR_RELAY empty in phpunit.xml: the peer is a child process
 * this test started on 127.0.0.1 and stops again. It is needed because the only
 * way to observe what signEvent() does AFTER handing the note to the relay is
 * to let that hand-off actually succeed — against an unreachable relay,
 * `Relay::send()` dies inside the vendor library (it catches the socket error
 * and then returns an array from a method declared `: RelayResponse`), so
 * everything below the send would be unreachable for reasons that have nothing
 * to do with the code under test.
 *
 * The relay has to survive more than one message: mounting the component runs
 * loadEvents() and loadBoardEvents(), which each open their own subscription
 * long before a vote is ever signed.
 *
 * @return array{0: string, 1: string, 2: Closure(): void} relay URL, path of the
 *                                                         file received events are appended to, and a stopper
 */
function startFakeNostrRelay(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port = (int) explode(':', (string) stream_socket_get_name($probe, false))[1];
    fclose($probe);

    $received = (string) tempnam(sys_get_temp_dir(), 'nostr-relay-payload-');
    $script = (string) tempnam(sys_get_temp_dir(), 'nostr-relay-server-').'.php';

    file_put_contents($script, <<<'PHP'
<?php
require $argv[1];

$server = new WebSocket\Server((int) $argv[2], false);
$server->onText(function ($server, $connection, $message) use ($argv) {
    $payload = json_decode($message->getContent(), true);

    switch ($payload[0] ?? '') {
        case 'EVENT':
            file_put_contents($argv[3], $message->getContent()."\n", FILE_APPEND);
            $connection->text(json_encode(['OK', $payload[1]['id'] ?? '', true, '']));
            break;
        case 'REQ':
            $connection->text(json_encode(['EOSE', $payload[1] ?? '']));
            break;
    }
});
$server->start();
PHP);

    $process = proc_open(
        [PHP_BINARY, $script, base_path('vendor/autoload.php'), (string) $port, $received],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes
    );

    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);

        if ($connection !== false) {
            fclose($connection);
            break;
        }

        usleep(50_000);
    }

    $stop = function () use ($process, $script, $received): void {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }

        @unlink($script);
        @unlink($received);
    };

    return ["ws://127.0.0.1:{$port}", $received, $stop];
}

/*
 * Regression guard for the removal of the dead broadcasting stack.
 *
 * signEvent() used to end on a static call into a `Broadcast` helper below
 * `app/Support/`. That class has never existed in this repository (`git log
 * --all -- app/Support/Broadcast.php` returns nothing), so the line raised a
 * fatal Error on every vote — but only AFTER the ballot had already gone to
 * the relay. The member therefore saw a 500 for a vote that had in fact been
 * cast.
 *
 * The assertion that matters is the absence of an exception: with the line
 * restored, `->call('signEvent', ...)` fails with a "class not found" Error
 * while the relay still records the event — exactly the split-brain behaviour
 * this removal fixes.
 */
it('sends the vote to the relay and runs signEvent through to the end', function () {
    [$relayUrl, $received, $stop] = startFakeNostrRelay();

    try {
        config()->set('services.relay', $relayUrl);

        $election = Election::factory()->create();
        $pleb = EinundzwanzigPleb::factory()->active()->withPaidCurrentYear()->create();

        NostrAuth::login($pleb->pubkey);

        $vote = [
            'id' => str_repeat('a', 64),
            'pubkey' => $pleb->pubkey,
            'created_at' => time(),
            'kind' => 2121,
            'tags' => [],
            'content' => $pleb->pubkey.',presidency',
            'sig' => str_repeat('b', 128),
        ];

        Livewire::test('association.election.show', ['election' => $election])
            ->call('signEvent', $vote)
            ->assertHasNoErrors();

        expect(file_get_contents($received))
            ->toContain('"id":"'.str_repeat('a', 64).'"')
            ->toContain('presidency');
    } finally {
        $stop();
    }
});
