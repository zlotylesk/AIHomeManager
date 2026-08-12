<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Backup;

use App\Infrastructure\Backup\BackupCipher;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupCipherTest extends TestCase
{
    private string $tmpDir;
    private string $key;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/aihm_cipher_test_'.uniqid();
        mkdir($this->tmpDir, 0o755, true);
        $this->key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
    }

    #[Override]
    protected function tearDown(): void
    {
        $files = glob($this->tmpDir.'/*');
        if (false !== $files) {
            array_map(unlink(...), $files);
        }
        rmdir($this->tmpDir);
    }

    public function testRoundTripsContent(): void
    {
        $cipher = new BackupCipher($this->key);
        $plaintext = "-- MySQL dump\nINSERT INTO series VALUES (1,'Severance');\n";

        self::assertSame($plaintext, $this->roundTrip($cipher, $plaintext));
    }

    /**
     * The dump is chunked at 64 KiB, so anything larger exercises the part of the
     * format a small fixture never reaches: several MESSAGE chunks before the
     * FINAL one, and the block-sized reads that have to line up with them on the
     * way back. A cipher that only ever round-trips one chunk is a cipher that
     * has never been tested against a real database.
     */
    public function testRoundTripsContentSpanningManyChunks(): void
    {
        $cipher = new BackupCipher($this->key);
        $plaintext = str_repeat('INSERT INTO episodes VALUES (1,2,3);', 20000);

        self::assertGreaterThan(65536 * 4, \strlen($plaintext));
        self::assertSame($plaintext, $this->roundTrip($cipher, $plaintext));
    }

    /**
     * A file whose length is an exact multiple of the chunk size is the boundary
     * where the encrypt loop must still emit one more (empty) chunk to carry the
     * FINAL tag. Get it wrong and the stream ends un-finalised — which the
     * truncation guard would then reject, turning a perfectly good backup into an
     * unrestorable one.
     */
    public function testRoundTripsContentOfExactlyOneChunk(): void
    {
        $cipher = new BackupCipher($this->key);
        $plaintext = str_repeat('x', 65536);

        self::assertSame($plaintext, $this->roundTrip($cipher, $plaintext));
    }

    public function testRoundTripsEmptyContent(): void
    {
        $cipher = new BackupCipher($this->key);

        self::assertSame('', $this->roundTrip($cipher, ''));
    }

    public function testCiphertextDoesNotContainThePlaintext(): void
    {
        $cipher = new BackupCipher($this->key);
        $plain = $this->tmpDir.'/dump.sql';
        $encrypted = $this->tmpDir.'/dump.sql.enc';

        file_put_contents($plain, 'INSERT INTO budget_transactions VALUES (1, 4999);');
        $cipher->encryptFile($plain, $encrypted);

        $onDisk = file_get_contents($encrypted);
        self::assertIsString($onDisk);
        self::assertStringNotContainsString('budget_transactions', $onDisk);
    }

    public function testDecryptingWithAnotherKeyFails(): void
    {
        $encrypted = $this->tmpDir.'/dump.sql.enc';
        $plain = $this->tmpDir.'/dump.sql';
        file_put_contents($plain, 'INSERT INTO series VALUES (1);');

        new BackupCipher($this->key)->encryptFile($plain, $encrypted);

        $other = new BackupCipher(sodium_crypto_secretstream_xchacha20poly1305_keygen());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/wrong BACKUP_ENCRYPTION_KEY/');

        $other->decryptToStream($encrypted, $this->devNull());
    }

    /**
     * Authentication is the reason to prefer an AEAD over raw encryption here:
     * a backup that has passed through somebody else's storage must not be able
     * to come back as a modified SQL script that then runs against the database.
     */
    public function testTamperedCiphertextIsRejected(): void
    {
        $encrypted = $this->tmpDir.'/dump.sql.enc';
        $plain = $this->tmpDir.'/dump.sql';
        file_put_contents($plain, str_repeat('INSERT INTO series VALUES (1);', 100));

        new BackupCipher($this->key)->encryptFile($plain, $encrypted);

        $bytes = file_get_contents($encrypted);
        self::assertIsString($bytes);
        $victim = \strlen($bytes) - 20;
        $bytes[$victim] = 'A' === $bytes[$victim] ? 'B' : 'A';
        file_put_contents($encrypted, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/altered in transit/');

        new BackupCipher($this->key)->decryptToStream($encrypted, $this->devNull());
    }

    /**
     * The case that motivated the FINAL tag check, and the only one where the
     * danger is silent. An upload cut exactly on a chunk boundary leaves a file
     * whose every surviving chunk authenticates perfectly — so without this guard
     * it decrypts cleanly to a partial dump, and a partial dump restores a
     * database missing whatever came after the cut, with nothing anywhere saying
     * so.
     *
     * The cut has to be on a boundary to test the guard rather than the MAC: the
     * header is the 7-byte magic plus a 24-byte stream header, and each block is
     * 64 KiB of plaintext plus a 17-byte tag.
     */
    public function testCiphertextTruncatedOnAChunkBoundaryIsRejected(): void
    {
        $encrypted = $this->tmpDir.'/dump.sql.enc';
        $plain = $this->tmpDir.'/dump.sql';
        file_put_contents($plain, str_repeat('INSERT INTO episodes VALUES (1);', 8000));

        $cipher = new BackupCipher($this->key);
        $cipher->encryptFile($plain, $encrypted);

        $bytes = file_get_contents($encrypted);
        self::assertIsString($bytes);
        $oneWholeBlock = 7 + 24 + (65536 + 17);
        self::assertGreaterThan($oneWholeBlock, \strlen($bytes));
        file_put_contents($encrypted, substr($bytes, 0, $oneWholeBlock));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ends without a final chunk/');

        $cipher->decryptToStream($encrypted, $this->devNull());
    }

    /**
     * A cut anywhere else is caught one step earlier, by the chunk's own tag. It
     * is worth its own test because the message differs on purpose: the key
     * decrypted the earlier chunks, so telling the operator to go looking for a
     * different key would send them after a problem they do not have.
     */
    public function testCiphertextTruncatedMidChunkBlamesTheFileRatherThanTheKey(): void
    {
        $encrypted = $this->tmpDir.'/dump.sql.enc';
        $plain = $this->tmpDir.'/dump.sql';
        file_put_contents($plain, str_repeat('INSERT INTO episodes VALUES (1);', 8000));

        $cipher = new BackupCipher($this->key);
        $cipher->encryptFile($plain, $encrypted);

        $bytes = file_get_contents($encrypted);
        self::assertIsString($bytes);
        file_put_contents($encrypted, substr($bytes, 0, (int) (\strlen($bytes) / 2)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/damaged or truncated at chunk \d+ — the key is right/');

        $cipher->decryptToStream($encrypted, $this->devNull());
    }

    public function testPlainGzipIsRejectedWithAnActionableMessage(): void
    {
        $notEncrypted = $this->tmpDir.'/legacy.sql.gz';
        file_put_contents($notEncrypted, gzencode('INSERT INTO series VALUES (1);'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not an encrypted AIHM backup/');

        new BackupCipher($this->key)->decryptToStream($notEncrypted, $this->devNull());
    }

    public function testRejectsAKeyOfTheWrongLength(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be 32 bytes, got 5/');

        new BackupCipher('short');
    }

    private function roundTrip(BackupCipher $cipher, string $plaintext): string
    {
        $plain = $this->tmpDir.'/dump.sql';
        $encrypted = $this->tmpDir.'/dump.sql.enc';
        $restored = $this->tmpDir.'/restored.sql';

        file_put_contents($plain, $plaintext);
        $cipher->encryptFile($plain, $encrypted);

        $out = fopen($restored, 'wb');
        self::assertIsResource($out);
        $cipher->decryptToStream($encrypted, $out);
        fclose($out);

        $content = file_get_contents($restored);
        self::assertIsString($content);

        return $content;
    }

    /** @return resource */
    private function devNull()
    {
        $handle = fopen('php://memory', 'wb');
        self::assertIsResource($handle);

        return $handle;
    }
}
