<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Music\Infrastructure;

use App\Module\Music\Infrastructure\Security\TokenCipher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TokenCipherTest extends TestCase
{
    private const string KEY_B64 = 'KwfMWKTYtCMEXZtS1DNr68HJQ1rBykWYTqx9ZqN6p8w=';

    private TokenCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new TokenCipher(base64_decode(self::KEY_B64));
    }

    public function testEncryptDecryptRoundtrip(): void
    {
        $plaintext = 'oauth_access_token_value_12345';

        $ciphertext = $this->cipher->encrypt($plaintext);

        self::assertNotSame($plaintext, $ciphertext);
        self::assertSame($plaintext, $this->cipher->decrypt($ciphertext));
    }

    public function testEncryptProducesDistinctOutputForSamePlaintext(): void
    {
        $plaintext = 'same_token';

        self::assertNotSame(
            $this->cipher->encrypt($plaintext),
            $this->cipher->encrypt($plaintext),
        );
    }

    public function testDecryptFailsOnTamperedCiphertext(): void
    {
        $ciphertext = $this->cipher->encrypt('original');
        $decoded = base64_decode($ciphertext);
        $tampered = base64_encode(substr($decoded, 0, -1).chr(\ord($decoded[\strlen($decoded) - 1]) ^ 1));

        $this->expectException(RuntimeException::class);
        $this->cipher->decrypt($tampered);
    }

    public function testDecryptFailsWithDifferentKey(): void
    {
        $ciphertext = $this->cipher->encrypt('secret');
        $otherCipher = new TokenCipher(sodium_crypto_secretbox_keygen());

        $this->expectException(RuntimeException::class);
        $otherCipher->decrypt($ciphertext);
    }

    public function testDecryptFailsOnInvalidBase64(): void
    {
        $this->expectException(RuntimeException::class);
        $this->cipher->decrypt('not-valid-base64!!!');
    }

    public function testConstructorRejectsWrongKeyLength(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TokenCipher key must be 32 bytes');
        new TokenCipher('too-short-key');
    }
}
