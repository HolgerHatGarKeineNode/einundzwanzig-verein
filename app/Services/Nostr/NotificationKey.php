<?php

namespace App\Services\Nostr;

use swentel\nostr\Encryption\Nip44;
use swentel\nostr\Key\Key;
use Throwable;

/**
 * The association's notification key (E16), and the only code that reads it.
 *
 * It signs exactly two shapes and nothing else: the kind 13 seal of a direct
 * message and the kind 22242 answer to a relay's AUTH challenge. There is no
 * method that takes a kind — the removed election feature failed on exactly
 * such an open signing method.
 *
 * The configuration is read when the key is first needed, not when the class
 * is constructed: an installation without the key boots, runs the fee job and
 * every other feature, and fails loudly (NotificationKeyUnavailable) on the
 * first direct message instead.
 */
final class NotificationKey
{
    public const int KIND_SEAL = 13;

    public const int KIND_RELAY_AUTH = 22242;

    private ?string $secretHex = null;

    private ?string $publicKey = null;

    public function __construct(#[\SensitiveParameter] private readonly ?string $configured) {}

    public static function fromConfig(): self
    {
        $configured = config('services.nostr_notification_key');

        return new self(is_string($configured) ? $configured : null);
    }

    /**
     * @throws NotificationKeyUnavailable
     */
    public function publicKey(): string
    {
        return $this->publicKey ??= (new Key)->getPublicKey($this->secret());
    }

    /**
     * The NIP-44 v2 conversation key between this key and a recipient.
     *
     * @throws NotificationKeyUnavailable
     */
    public function conversationKeyWith(string $recipientPubkey): string
    {
        return Nip44::getConversationKey($this->secret(), $recipientPubkey);
    }

    /**
     * Sign the seal of a direct message: kind 13, tags always empty (NIP-59).
     *
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: array<int, array<int, string>>, content: string, sig: string}
     *
     * @throws NotificationKeyUnavailable
     */
    public function signSeal(string $encryptedRumor, int $createdAt): array
    {
        return EventSigning::sign($this->secret(), self::KIND_SEAL, [], $encryptedRumor, $createdAt);
    }

    /**
     * Sign the answer to a relay's AUTH challenge (NIP-42).
     *
     * @return array{id: string, pubkey: string, created_at: int, kind: int, tags: array<int, array<int, string>>, content: string, sig: string}
     *
     * @throws NotificationKeyUnavailable
     */
    public function signRelayAuth(string $relayUrl, string $challenge, int $createdAt): array
    {
        return EventSigning::sign(
            $this->secret(),
            self::KIND_RELAY_AUTH,
            [['relay', $relayUrl], ['challenge', $challenge]],
            '',
            $createdAt,
        );
    }

    /**
     * Keeps the secret out of dump(), dd() and var_dump().
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['configured' => $this->configured === null || trim($this->configured) === '' ? 'no' : 'yes'];
    }

    /**
     * @throws NotificationKeyUnavailable
     */
    private function secret(): string
    {
        if ($this->secretHex !== null) {
            return $this->secretHex;
        }

        $configured = trim((string) $this->configured);

        if ($configured === '') {
            throw NotificationKeyUnavailable::notConfigured();
        }

        if (str_starts_with($configured, 'nsec1')) {
            try {
                $configured = (new Key)->convertToHex($configured);
            } catch (Throwable) {
                throw NotificationKeyUnavailable::malformed();
            }
        }

        $configured = strtolower($configured);

        if (! EventSigning::isValidSecret($configured)) {
            throw NotificationKeyUnavailable::malformed();
        }

        return $this->secretHex = $configured;
    }
}
