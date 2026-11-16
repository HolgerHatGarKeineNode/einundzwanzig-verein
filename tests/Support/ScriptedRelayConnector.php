<?php

namespace Tests\Support;

use App\Services\Nostr\Relay\PinnedRelayAddress;
use App\Services\Nostr\Relay\RelayConnection;
use App\Services\Nostr\Relay\RelayConnectionTimedOut;
use App\Services\Nostr\Relay\RelayConnector;
use Closure;
use Throwable;

/**
 * A relay that speaks from a script, frame by frame, for testing the protocol
 * logic of App\Services\Nostr\NostrRelayTransport without a socket.
 *
 * Each reply is played on one receive(), in order:
 *
 *     $relay = (new ScriptedRelayConnector)
 *         ->reply(['AUTH', 'challenge-1'])
 *         ->reply(fn (array $sent) => ['OK', $sent[1][1]['id'], true, ''])
 *         ->reply(fn (array $sent) => ['OK', $sent[0][1]['id'], true, '']);
 *
 * A closure receives every frame the client has sent so far, JSON-decoded, so
 * it can answer with the real event or AUTH id. A string is sent as it is
 * (for frames that are not JSON); null stands for a frame without text (a
 * ping); a Throwable is thrown from receive(). When the script runs out,
 * receive() throws RelayConnectionTimedOut — the deadline passed.
 */
final class ScriptedRelayConnector implements RelayConnector
{
    /** @var array<int, Closure|array<int, mixed>|string|Throwable|null> */
    private array $script = [];

    /** @var array<int, array<int, mixed>> */
    private array $sent = [];

    /** @var array<int, PinnedRelayAddress> */
    private array $connections = [];

    private ?Throwable $connectFailure = null;

    private bool $closed = false;

    /**
     * @param  Closure|array<int, mixed>|string|Throwable|null  $frame
     */
    public function reply(Closure|array|string|Throwable|null $frame): self
    {
        $this->script[] = $frame;

        return $this;
    }

    public function failToConnect(Throwable $exception): self
    {
        $this->connectFailure = $exception;

        return $this;
    }

    public function connect(PinnedRelayAddress $address, float $deadline): RelayConnection
    {
        $this->connections[] = $address;

        if ($this->connectFailure !== null) {
            throw $this->connectFailure;
        }

        $connector = $this;

        return new class($connector) implements RelayConnection
        {
            public function __construct(private readonly ScriptedRelayConnector $connector) {}

            public function send(string $frame): void
            {
                $this->connector->recordSent($frame);
            }

            public function receive(): ?string
            {
                return $this->connector->nextFrame();
            }

            public function close(): void
            {
                $this->connector->markClosed();
            }
        };
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * @return array<int, string>
     */
    public function sentTypes(): array
    {
        return array_map(fn (array $frame): string => (string) $frame[0], $this->sent);
    }

    /**
     * @return array<int, PinnedRelayAddress>
     */
    public function connections(): array
    {
        return $this->connections;
    }

    public function wasClosed(): bool
    {
        return $this->closed;
    }

    /**
     * @internal called by the connection
     */
    public function recordSent(string $frame): void
    {
        $this->sent[] = json_decode($frame, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @internal called by the connection
     */
    public function nextFrame(): ?string
    {
        if ($this->script === []) {
            throw new RelayConnectionTimedOut('The relay deadline passed (script exhausted).');
        }

        $frame = array_shift($this->script);

        if ($frame instanceof Closure) {
            $frame = $frame($this->sent);
        }

        if ($frame instanceof Throwable) {
            throw $frame;
        }

        if (is_array($frame)) {
            return json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $frame;
    }

    /**
     * @internal called by the connection
     */
    public function markClosed(): void
    {
        $this->closed = true;
    }
}
