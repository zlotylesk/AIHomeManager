<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;
use SodiumException;

final readonly class TokenCipher
{
    public function __construct(private string $key)
    {
        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($this->key)) {
            throw new RuntimeException(sprintf('TokenCipher key must be %d bytes, got %d. Generate with: base64_encode(sodium_crypto_secretbox_keygen()).', SODIUM_CRYPTO_SECRETBOX_KEYBYTES, \strlen($this->key)));
        }
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce.$ciphertext);
    }

    public function decrypt(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if (false === $decoded || \strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('TokenCipher: invalid ciphertext.');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        } catch (SodiumException $e) {
            throw new RuntimeException('TokenCipher: decryption failed.', previous: $e);
        }

        if (false === $plaintext) {
            throw new RuntimeException('TokenCipher: decryption failed (MAC mismatch).');
        }

        return $plaintext;
    }
}
