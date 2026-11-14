<?php

namespace App\Services\Nostr\Relay\WebSocket;

use App\Services\Nostr\Relay\RelayConnection;
use App\Services\Nostr\Relay\RelayConnectionFailed;
use App\Services\Nostr\Relay\RelayConnectionTimedOut;
use Throwable;
use WebSocket\Client;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Text;

/**
 * An open phrity/websocket client, behind RelayConnection.
 *
 * Every Throwable from the library — its own exceptions, and the TypeErrors it
 * raises on a relay that answers nothing usable — leaves this class as a
 * RelayConnectionFailed.
 */
final class WebSocketRelayConnection implements RelayConnection
{
    public function __construct(
        private readonly Client $client,
        private readonly BoundedStreamFactory $factory,
        private readonly float $deadline,
    ) {}

    public function send(string $frame): void
    {
        try {
            $this->client->text($frame);
        } catch (Throwable $exception) {
            throw self::translate($exception, $this->factory, $this->deadline, 'send');
        }
    }

    public function receive(): ?string
    {
        try {
            $message = $this->client->receive();
        } catch (Throwable $exception) {
            throw self::translate($exception, $this->factory, $this->deadline, 'receive');
        }

        return $message instanceof Text ? $message->getContent() : null;
    }

    public function close(): void
    {
        try {
            $this->client->disconnect();
        } catch (Throwable) {
            // Closing a broken connection has nothing left to report.
        }
    }

    public static function translate(
        Throwable $exception,
        BoundedStreamFactory $factory,
        float $deadline,
        string $operation,
    ): RelayConnectionFailed {
        if ($exception instanceof RelayConnectionFailed) {
            return $exception;
        }

        $failure = $factory->stream?->failure();

        if ($failure === BoundedSocketStream::FAILURE_TIMEOUT
            || $exception instanceof ConnectionTimeoutException
            || hrtime(true) / 1e9 >= $deadline) {
            return new RelayConnectionTimedOut("The relay deadline passed during {$operation}.");
        }

        if ($failure === BoundedSocketStream::FAILURE_TOO_LARGE) {
            return new RelayConnectionFailed("The relay sent more data than allowed during {$operation}.");
        }

        $reason = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $exception->getMessage()) ?? '', 0, 200);

        return new RelayConnectionFailed(
            "{$operation} failed (".class_basename($exception).($reason === '' ? '' : ": {$reason}").')',
        );
    }
}
