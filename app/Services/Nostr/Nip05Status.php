<?php

namespace App\Services\Nostr;

use Livewire\Wireable;

/**
 * What the association's nostr.json says about a member's NIP-05 handle.
 *
 * `handles` are the names the directory lists for the member's pubkey. The
 * handle is verified once the directory lists any name for the key, and a
 * mismatch means the stored handle is not (yet) among them — the directory is
 * rebuilt in the background, so a freshly saved handle shows up there later.
 * A member without a stored handle has no status at all.
 *
 * Wireable so a page can hold it as one locked property.
 */
final readonly class Nip05Status implements Wireable
{
    public bool $verified;

    public ?string $verifiedHandle;

    /**
     * @param  list<string>  $handles
     */
    private function __construct(public array $handles, public bool $mismatch)
    {
        $this->verified = $handles !== [];
        $this->verifiedHandle = $handles[0] ?? null;
    }

    public static function none(): self
    {
        return new self([], false);
    }

    /**
     * @param  list<string>  $listedHandles  the names nostr.json lists for the member's pubkey
     */
    public static function of(?string $storedHandle, array $listedHandles): self
    {
        if (blank($storedHandle) || $listedHandles === []) {
            return self::none();
        }

        return new self($listedHandles, ! in_array($storedHandle, $listedHandles, true));
    }

    /**
     * @return array{handles: list<string>, mismatch: bool}
     */
    public function toLivewire(): array
    {
        return ['handles' => $this->handles, 'mismatch' => $this->mismatch];
    }

    /**
     * Livewire hydrates an update to the property through this method before
     * its #[Locked] guard runs, so `$value` may be any JSON a request sent, not
     * only what toLivewire() produced. A malformed value yields the empty
     * status instead of an "Undefined array key" 500; the locked guard then
     * rejects the update as intended.
     *
     * @param  mixed  $value  normally array{handles: list<string>, mismatch: bool}
     */
    public static function fromLivewire($value): self
    {
        if (! is_array($value)
            || ! is_array($value['handles'] ?? null)
            || ! array_is_list($value['handles'])
            || array_filter($value['handles'], fn (mixed $handle): bool => ! is_string($handle)) !== []
            || ! is_bool($value['mismatch'] ?? null)) {
            return self::none();
        }

        return new self($value['handles'], $value['mismatch']);
    }
}
