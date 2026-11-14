<?php

namespace App\Services\Nostr\Relay\WebSocket;

use App\Services\Nostr\Relay\RelayConnectionFailed;
use App\Services\Nostr\Relay\RelayConnectionTimedOut;
use Phrity\Net\SocketStream;

/**
 * The relay socket with a byte budget and a deadline.
 *
 * phrity/websocket reads a frame by asking for exactly the length the frame
 * header announces, and fread() allocates that length up front: one header
 * claiming 2^62 bytes ends the PHP process with a fatal "Allowed memory size
 * exhausted" that no catch block sees. The handshake reader loops over header
 * lines with no limit either, and a per-read timeout lets a relay that drips
 * one byte at a time hold the worker forever. So every read here checks the
 * deadline first and refuses a length beyond the remaining budget before
 * anything is allocated.
 *
 * The reason for a failure is kept on the stream (failure()): phrity converts
 * every exception from below into its own, without the original, and the
 * connection needs to tell a timeout from an oversized frame afterwards.
 */
final class BoundedSocketStream extends SocketStream
{
    public const string FAILURE_TIMEOUT = 'timeout';

    public const string FAILURE_TOO_LARGE = 'too_large';

    private int $bytesRead = 0;

    private ?string $failure = null;

    /**
     * @param  resource  $stream
     */
    public function __construct(
        $stream,
        private readonly float $deadline,
        private readonly int $maxInboundBytes,
    ) {
        parent::__construct($stream);
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    public function read(int $length): string
    {
        $this->beforeIo();

        if ($length > $this->maxInboundBytes - $this->bytesRead) {
            $this->tooLarge();
        }

        $data = parent::read($length);
        $this->afterRead($data);

        return $data;
    }

    public function readLine(int $length): ?string
    {
        $this->beforeIo();

        $remaining = $this->maxInboundBytes - $this->bytesRead;

        if ($remaining < 1) {
            $this->tooLarge();
        }

        // fgets() reads at most $length - 1 bytes.
        $line = parent::readLine(min($length, $remaining + 1));
        $this->afterRead($line ?? '');

        return $line;
    }

    public function write(string $string): int
    {
        $this->beforeIo();

        return parent::write($string);
    }

    private function beforeIo(): void
    {
        $remaining = $this->deadline - hrtime(true) / 1e9;

        if ($remaining <= 0) {
            $this->failure = self::FAILURE_TIMEOUT;

            throw new RelayConnectionTimedOut('The relay deadline passed.');
        }

        $this->setTimeout($remaining);
    }

    private function afterRead(string $data): void
    {
        $this->bytesRead += strlen($data);

        if ($data === '' && $this->getMetadata('timed_out') === true) {
            $this->failure = self::FAILURE_TIMEOUT;

            throw new RelayConnectionTimedOut('The relay deadline passed while waiting for data.');
        }

        if ($this->bytesRead > $this->maxInboundBytes) {
            $this->tooLarge();
        }
    }

    private function tooLarge(): never
    {
        $this->failure = self::FAILURE_TOO_LARGE;

        throw new RelayConnectionFailed("The relay sent more than {$this->maxInboundBytes} bytes.");
    }
}
