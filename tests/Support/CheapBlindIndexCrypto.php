<?php

namespace Tests\Support;

use ParagonIE\CipherSweet\Backend\Key\SymmetricKey;
use ParagonIE\CipherSweet\Backend\ModernCrypto;
use ParagonIE\CipherSweet\Contract\BackendInterface;
use ParagonIE\CipherSweet\Util;

/**
 * CipherSweet's ModernCrypto backend with the blind index KDF at libsodium's
 * minimum cost — the test suite's BCRYPT_ROUNDS=4 for the member e-mail.
 *
 * Why: EinundzwanzigPleb keeps a slow blind index of `email`
 * (`new BlindIndex('email_index')`), which ModernCrypto computes with
 * Argon2id at the INTERACTIVE limits: 64 MiB and two passes, about 35 ms per
 * saved member (measured 2026-11-15: 35.8 ms per factory create, 37.2 ms per
 * bare sodium_crypto_pwhash call). It runs on every save, also for a member
 * without an e-mail address. The suite creates thousands of members, so this
 * one hash was a large share of its wall time.
 *
 * What stays the same: the algorithm (Argon2id13), the salt derived from the
 * field key, the output length, the bit mask, and every other operation of
 * the backend — encryption, decryption and the fast index are inherited
 * unchanged. Only the work factor differs, which no test asserts and which
 * only matters against an attacker holding the database.
 *
 * Bound for the Feature suite in tests/Pest.php. tests/Engine keeps the
 * production backend.
 *
 * Invokable because spatie/laravel-ciphersweet builds a custom backend as
 * `(new $class())()`.
 */
final class CheapBlindIndexCrypto extends ModernCrypto
{
    /** libsodium's crypto_pwhash_argon2id_OPSLIMIT_MIN; ext-sodium exports no constant for it. */
    private const int ARGON2ID_OPSLIMIT_MIN = 1;

    /** libsodium's crypto_pwhash_argon2id_MEMLIMIT_MIN, in bytes. */
    private const int ARGON2ID_MEMLIMIT_MIN = 8192;

    public function __invoke(): BackendInterface
    {
        return $this;
    }

    public function blindIndexSlow(
        #[\SensitiveParameter]
        string $plaintext,
        #[\SensitiveParameter]
        SymmetricKey $key,
        ?int $bitLength = null,
        array $config = []
    ): string {
        $bitLength ??= 256;
        $length = max(16, $bitLength >> 3);

        // Silenced like the parent: an empty address raises "empty password".
        $hash = @sodium_crypto_pwhash(
            $length,
            $plaintext,
            sodium_crypto_generichash($key->getRawKey(), '', 16),
            self::ARGON2ID_OPSLIMIT_MIN,
            self::ARGON2ID_MEMLIMIT_MIN,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        return Util::andMask($hash, $bitLength);
    }
}
