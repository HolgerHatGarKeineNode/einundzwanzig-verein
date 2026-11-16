<?php

namespace App\Services\Nostr;

use App\Services\Nostr\Relay\RelayAddressGuard;
use App\Services\Nostr\Relay\RelayAddressRefused;
use App\Services\Nostr\Relay\RelayConnection;
use App\Services\Nostr\Relay\RelayConnectionTimedOut;
use App\Services\Nostr\Relay\RelayConnector;
use Carbon\CarbonImmutable;
use JsonException;
use Throwable;

/**
 * The production RelayTransport: NIP-01 publish and fetch, NIP-42 AUTH with
 * the notification key, over a connection to an address RelayAddressGuard
 * admitted.
 *
 * What the relay says decides the result, and only what it says about OUR
 * event: an `OK` counts when its id is the event's id AND its flag is the
 * JSON boolean true (or its reason starts with `duplicate:`). Anything else —
 * a NOTICE, an OK for another id, an OK whose flag is the string "true", a
 * frame that is not JSON — is not an answer, and the deadline decides.
 *
 * AUTH (NIP-42) only when the relay demands it. A challenge the relay sends
 * on connect is remembered, not answered: authenticating tells the operator
 * of the member's inbox relay that the association sent this gift wrap,
 * which is exactly the sender link NIP-59 exists to hide (jury run 2, M-1).
 * Only an `OK false "auth-required: …"` for our event makes us sign a kind
 * 22242 with the notification key — for the latest challenge, or for the
 * first one that arrives afterwards — and, once that AUTH is accepted, send
 * the event exactly once more. At most one AUTH and two EVENT frames per
 * connection; a second refusal is the result.
 *
 * fetch() does not authenticate: it reads public lists (kind 10050), and a
 * relay that insists on AUTH for that yields an incomplete result.
 */
final class NostrRelayTransport implements RelayTransport
{
    public const float DEADLINE_SECONDS = 10.0;

    /** Our wraps stay below this; see DirectMessageSigner::MAX_TEXT_BYTES. */
    public const int MAX_EVENT_FRAME_BYTES = 65_536;

    public const int MAX_FETCHED_EVENTS = 5_000;

    private const int MAX_REASON_LENGTH = 300;

    public function __construct(
        private readonly NotificationKey $key,
        private readonly RelayAddressGuard $guard,
        private readonly RelayConnector $connector,
        private readonly float $deadlineSeconds = self::DEADLINE_SECONDS,
    ) {}

    /**
     * @throws NotificationKeyUnavailable before any connection, when the key is missing or malformed
     */
    public function publish(string $relayUrl, array $event): RelayPublishResult
    {
        // Fixed before the DNS lookup in pin(): resolving is part of the budget.
        $deadline = $this->deadline();

        // A missing key is an installation problem, not a relay outcome.
        $this->key->publicKey();

        $eventId = $event['id'] ?? null;

        if (! is_string($eventId) || preg_match('/^[0-9a-f]{64}$/', $eventId) !== 1) {
            return RelayPublishResult::refused('refused: the event has no valid id');
        }

        try {
            $frame = EventHash::json(['EVENT', $event]);
        } catch (JsonException) {
            return RelayPublishResult::refused('refused: the event cannot be encoded as JSON');
        }

        if (strlen($frame) > self::MAX_EVENT_FRAME_BYTES) {
            return RelayPublishResult::refused('refused: the event is larger than '.self::MAX_EVENT_FRAME_BYTES.' bytes');
        }

        try {
            $address = $this->guard->pin($relayUrl);
        } catch (RelayAddressRefused $refusal) {
            return RelayPublishResult::refused($refusal->getMessage());
        }

        $trace = ['notice' => '', 'awaiting_auth' => ''];
        $connection = null;

        try {
            $connection = $this->connector->connect($address, $deadline);

            return $this->exchangeEvent($connection, trim($relayUrl), $frame, $eventId, $trace);
        } catch (RelayConnectionTimedOut) {
            if ($trace['awaiting_auth'] !== '') {
                return RelayPublishResult::rejected("{$trace['awaiting_auth']} (no AUTH completed before the deadline)");
            }

            return RelayPublishResult::failed(
                'timeout: no OK within '.$this->deadlineSeconds.' s'
                .($trace['notice'] === '' ? '' : " (last NOTICE: {$trace['notice']})"),
            );
        } catch (NotificationKeyUnavailable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return RelayPublishResult::failed('failed: '.self::clean($exception->getMessage()));
        } finally {
            $connection?->close();
        }
    }

    public function fetch(string $relayUrl, array $filter): RelayFetchResult
    {
        // Fixed before the DNS lookup in pin(): resolving is part of the budget.
        $deadline = $this->deadline();

        try {
            $address = $this->guard->pin($relayUrl);
        } catch (RelayAddressRefused $refusal) {
            return RelayFetchResult::incomplete([], $refusal->getMessage());
        }

        $events = [];
        $connection = null;
        $subscription = bin2hex(random_bytes(8));

        try {
            $connection = $this->connector->connect($address, $deadline);
            $connection->send(EventHash::json(['REQ', $subscription, (object) $filter]));

            while (true) {
                $message = self::decode($connection->receive());

                if ($message === null || ($message[1] ?? null) !== $subscription) {
                    continue;
                }

                if ($message[0] === 'EVENT' && is_array($message[2] ?? null)) {
                    $events[] = $message[2];

                    if (count($events) >= self::MAX_FETCHED_EVENTS) {
                        return RelayFetchResult::incomplete($events, 'stopped: more than '.self::MAX_FETCHED_EVENTS.' events');
                    }
                } elseif ($message[0] === 'EOSE') {
                    $this->sendQuietly($connection, EventHash::json(['CLOSE', $subscription]));

                    return RelayFetchResult::complete($events);
                } elseif ($message[0] === 'CLOSED') {
                    return RelayFetchResult::incomplete($events, 'closed: '.self::clean(self::text($message[2] ?? '')));
                }
            }
        } catch (RelayConnectionTimedOut) {
            return RelayFetchResult::incomplete($events, 'timeout: no EOSE within '.$this->deadlineSeconds.' s');
        } catch (Throwable $exception) {
            return RelayFetchResult::incomplete($events, 'failed: '.self::clean($exception->getMessage()));
        } finally {
            $connection?->close();
        }
    }

    /**
     * @param  array{notice: string, awaiting_auth: string}  $trace  what the caller reports if the deadline ends the exchange
     */
    private function exchangeEvent(
        RelayConnection $connection,
        string $relayUrl,
        string $frame,
        string $eventId,
        array &$trace,
    ): RelayPublishResult {
        $connection->send($frame);

        // The latest challenge the relay offered (NIP-42: a newer one replaces
        // it). Remembered, never answered on its own.
        $challenge = null;
        $authEventId = null;
        $resent = false;

        $authenticate = function (string $challenge) use ($connection, $relayUrl, &$authEventId): void {
            $auth = $this->key->signRelayAuth($relayUrl, $challenge, CarbonImmutable::now()->getTimestamp());
            $authEventId = $auth['id'];
            $connection->send(EventHash::json(['AUTH', $auth]));
        };

        while (true) {
            $message = self::decode($connection->receive());

            if ($message === null) {
                continue;
            }

            if ($message[0] === 'NOTICE') {
                $trace['notice'] = self::clean(self::text($message[1] ?? ''));

                continue;
            }

            if ($message[0] === 'AUTH') {
                $offered = $message[1] ?? null;

                if (! is_string($offered) || $offered === '') {
                    continue;
                }

                $challenge = $offered;

                // The relay asked for AUTH before it sent a challenge.
                if ($trace['awaiting_auth'] !== '' && $authEventId === null) {
                    $authenticate($challenge);
                }

                continue;
            }

            if ($message[0] !== 'OK' || ! is_string($message[1] ?? null) || ! is_bool($message[2] ?? null)) {
                continue;
            }

            [, $id, $ok] = $message;
            $reason = self::clean(self::text($message[3] ?? ''));

            if ($authEventId !== null && $id === $authEventId) {
                if ($trace['awaiting_auth'] === '') {
                    continue;
                }

                if (! $ok) {
                    return RelayPublishResult::rejected("{$trace['awaiting_auth']} (AUTH refused: {$reason})");
                }

                $trace['awaiting_auth'] = '';
                $resent = true;
                $connection->send($frame);

                continue;
            }

            if ($id !== $eventId) {
                continue;
            }

            if ($ok === true || str_starts_with($reason, 'duplicate:')) {
                return RelayPublishResult::accepted($reason);
            }

            if (! $resent && $authEventId === null && str_starts_with($reason, 'auth-required:')) {
                $trace['awaiting_auth'] = $reason;

                // Without a challenge yet, the next AUTH frame triggers it.
                if ($challenge !== null) {
                    $authenticate($challenge);
                }

                continue;
            }

            return RelayPublishResult::rejected($reason);
        }
    }

    private function deadline(): float
    {
        return hrtime(true) / 1e9 + $this->deadlineSeconds;
    }

    private function sendQuietly(RelayConnection $connection, string $frame): void
    {
        try {
            $connection->send($frame);
        } catch (Throwable) {
            // The answer is complete; a lost CLOSE changes nothing for us.
        }
    }

    /**
     * A relay message as a list whose first element is its type, or null.
     *
     * @return array<int, mixed>|null
     */
    private static function decode(?string $text): ?array
    {
        if ($text === null) {
            return null;
        }

        try {
            $message = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($message) || ! array_is_list($message) || ! is_string($message[0] ?? null)) {
            return null;
        }

        return $message;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * Relay-supplied text, safe to store and show: no control characters,
     * bounded length.
     */
    private static function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';

        return mb_substr(trim($text), 0, self::MAX_REASON_LENGTH);
    }
}
