<?php

namespace Tests\Support;

use App\Services\Nostr\RelayFetchResult;
use App\Services\Nostr\RelayPublishResult;
use App\Services\Nostr\RelayTransport;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * A relay that exists only in memory: it records what it is given and answers
 * what the test told it to.
 *
 * Bind it in the test that needs a relay:
 *
 *     $relay = FakeRelayTransport::bind();
 *     $relay->thenReject('blocked: not on the allow list');
 *
 * Unscripted publishes are accepted. Scripted outcomes are played in the
 * order they were added, one per publish, across all relays.
 *
 * fetch() returns the events stored for the asked relay and records the
 * filter. It does not evaluate the filter — a test that depends on filtering
 * asserts the recorded filter instead. A relay marked unanswering returns an
 * incomplete result, the way the production adapter reports a timeout.
 */
final class FakeRelayTransport implements RelayTransport
{
    /** @var array<int, array{relay: string, event: array<string, mixed>, accepted: bool}> */
    private array $published = [];

    /** @var array<int, array{relay: string, filter: array<string, mixed>}> */
    private array $fetched = [];

    /** @var array<int, array{result: RelayPublishResult, throw: ?Throwable}> */
    private array $outcomes = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $stored = [];

    /** @var array<string, string> */
    private array $unanswering = [];

    public static function bind(): self
    {
        $fake = new self;

        app()->instance(RelayTransport::class, $fake);

        return $fake;
    }

    public function thenAccept(string $message = ''): self
    {
        $this->outcomes[] = ['result' => RelayPublishResult::accepted($message), 'throw' => null];

        return $this;
    }

    public function thenReject(string $message): self
    {
        $this->outcomes[] = ['result' => RelayPublishResult::rejected($message), 'throw' => null];

        return $this;
    }

    /**
     * No usable answer, reported the way the production adapter reports it:
     * as a result with RelayPublishStatus::Failed.
     */
    public function thenFail(string $message): self
    {
        $this->outcomes[] = ['result' => RelayPublishResult::failed($message), 'throw' => null];

        return $this;
    }

    /**
     * Our own checks stopped the publish before any connection.
     */
    public function thenRefuse(string $message): self
    {
        $this->outcomes[] = ['result' => RelayPublishResult::refused($message), 'throw' => null];

        return $this;
    }

    /**
     * The relay never took the event and the transport threw. The production
     * adapter maps connection errors to thenFail(); this remains for code that
     * must survive a transport that throws anyway.
     */
    public function thenThrow(Throwable $exception): self
    {
        $this->outcomes[] = ['result' => RelayPublishResult::rejected($exception->getMessage()), 'throw' => $exception];

        return $this;
    }

    /**
     * The relay took the event and the caller never learns it: the connection
     * broke after `OK true`. A retry now meets a relay that already has the
     * event.
     */
    public function thenAcceptAndThrow(Throwable $exception): self
    {
        $this->outcomes[] = ['result' => RelayPublishResult::accepted(), 'throw' => $exception];

        return $this;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public function withStoredEvents(string $relayUrl, array $events): self
    {
        $this->stored[$relayUrl] = $events;

        return $this;
    }

    /**
     * fetch() on this relay ends without EOSE: what it returns is incomplete.
     */
    public function withUnansweringRelay(string $relayUrl, string $message = 'timeout: no EOSE'): self
    {
        $this->unanswering[$relayUrl] = $message;

        return $this;
    }

    public function publish(string $relayUrl, array $event): RelayPublishResult
    {
        $outcome = array_shift($this->outcomes)
            ?? ['result' => RelayPublishResult::accepted(), 'throw' => null];

        $this->published[] = [
            'relay' => $relayUrl,
            'event' => $event,
            'accepted' => $outcome['result']->accepted,
        ];

        if ($outcome['throw'] !== null) {
            throw $outcome['throw'];
        }

        return $outcome['result'];
    }

    public function fetch(string $relayUrl, array $filter): RelayFetchResult
    {
        $this->fetched[] = ['relay' => $relayUrl, 'filter' => $filter];

        if (isset($this->unanswering[$relayUrl])) {
            return RelayFetchResult::incomplete($this->stored[$relayUrl] ?? [], $this->unanswering[$relayUrl]);
        }

        return RelayFetchResult::complete($this->stored[$relayUrl] ?? []);
    }

    /**
     * Every publish in call order, with whether the relay took the event.
     *
     * @return array<int, array{relay: string, event: array<string, mixed>, accepted: bool}>
     */
    public function published(): array
    {
        return $this->published;
    }

    /**
     * @return array<int, array{relay: string, filter: array<string, mixed>}>
     */
    public function fetched(): array
    {
        return $this->fetched;
    }

    public function assertNothingPublished(): void
    {
        Assert::assertSame(
            [],
            array_map(
                fn (array $publish): string => "kind {$publish['event']['kind']} to {$publish['relay']}",
                $this->published,
            ),
            'Events were handed to a relay although none were expected.',
        );
    }

    public function assertPublishedCount(int $expected): void
    {
        Assert::assertCount($expected, $this->published, 'Unexpected number of events handed to relays.');
    }
}
