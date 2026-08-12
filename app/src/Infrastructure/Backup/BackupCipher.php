<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use RuntimeException;
use SodiumException;

/**
 * Encrypts a database dump on its way to disk, and decrypts it on the way back.
 *
 * Deliberately NOT the `secretbox` construction `App\Security\TokenCipher` uses.
 * That one takes a whole string and returns a whole string, which is right for a
 * few hundred bytes of token JSON and wrong for a dump: it would hold the entire
 * database in memory twice, in a scheduler worker sized for neither. This uses
 * the `secretstream` API, which libsodium provides for exactly this — a file
 * read, encrypted and written in fixed chunks, with the peak memory cost of one
 * chunk no matter how large the database grows.
 *
 * The stream is authenticated per chunk, so a bit flipped anywhere in a
 * transferred file is caught at that chunk rather than restored as data. The
 * final chunk additionally carries a FINAL tag, and {@see decryptToStream}
 * refuses a file that ends without it: an upload cut off halfway otherwise
 * decrypts perfectly up to the cut, and a truncated SQL dump restores a database
 * that is silently missing its last tables. That is the failure this whole
 * ticket exists to remove, so it is an error, not a partial success.
 */
final readonly class BackupCipher
{
    /**
     * Appended to the plain `.sql.gz` name rather than replacing it, so the
     * layering stays readable from the filename alone: gzip inside, ciphertext
     * outside. Compression has to happen first — encrypted bytes do not compress.
     */
    public const string FILE_EXTENSION = '.enc';

    /**
     * Lets a wrong file fail with "this is not an encrypted backup" instead of a
     * MAC error, which would otherwise read as "your key is wrong" — the single
     * most alarming possible message to get during a restore. The trailing byte
     * is a format version: a future change of chunk size or construction can be
     * detected rather than guessed at.
     */
    private const string MAGIC = "AIHMBK\x01";

    /**
     * 64 KiB of plaintext per chunk. Small enough that memory is flat, large
     * enough that the 17-byte-per-chunk authentication overhead is noise
     * (~0.03 %). It is part of the on-disk format: decryption reads back blocks
     * of exactly this size plus the tag, so changing it breaks every existing
     * backup and needs a new MAGIC version byte.
     */
    private const int CHUNK_BYTES = 65536;

    public function __construct(private string $key)
    {
        if (SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES !== \strlen($this->key)) {
            throw new RuntimeException(sprintf('BackupCipher key must be %d bytes, got %d. Generate with: php -r "echo base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());"', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, \strlen($this->key)));
        }
    }

    /**
     * Encrypts $plaintextPath to $ciphertextPath, chunk by chunk.
     *
     * The caller is expected to delete the plaintext afterwards — this method
     * does not, because deciding when the source may go is the caller's business
     * and a cipher that unlinks its own input cannot be used to make a copy.
     */
    public function encryptFile(string $plaintextPath, string $ciphertextPath): void
    {
        $in = @fopen($plaintextPath, 'rb');
        if (false === $in) {
            throw new RuntimeException(sprintf('BackupCipher: cannot read %s.', $plaintextPath));
        }

        $out = @fopen($ciphertextPath, 'wb');
        if (false === $out) {
            fclose($in);

            throw new RuntimeException(sprintf('BackupCipher: cannot write %s.', $ciphertextPath));
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);

            $this->write($out, self::MAGIC.$header, $ciphertextPath);

            while (true) {
                $chunk = fread($in, self::CHUNK_BYTES);
                if (false === $chunk) {
                    throw new RuntimeException(sprintf('BackupCipher: read failed on %s.', $plaintextPath));
                }

                // feof() only becomes true once a read has hit the end, so a file
                // whose length is an exact multiple of the chunk size gets one
                // final empty chunk here. That is correct rather than wasteful:
                // it is what carries the FINAL tag, and it is how a zero-byte
                // input still produces a well-formed stream.
                $isLast = feof($in);

                $this->write($out, sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $chunk,
                    '',
                    $isLast
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                ), $ciphertextPath);

                if ($isLast) {
                    break;
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * Decrypts $ciphertextPath and writes the plaintext to an already-open
     * stream — stdout for `app:backup:decrypt`, a file handle for a test.
     *
     * Streaming to a handle rather than returning a string is the whole point:
     * `make restore` pipes this straight into `mysql`, so the dump is never
     * materialised anywhere, neither in memory nor as a plaintext file on the
     * disk we just went to the trouble of not leaving it on.
     *
     * @param resource $out
     */
    public function decryptToStream(string $ciphertextPath, $out): void
    {
        $in = @fopen($ciphertextPath, 'rb');
        if (false === $in) {
            throw new RuntimeException(sprintf('BackupCipher: cannot read %s.', $ciphertextPath));
        }

        try {
            $magic = fread($in, \strlen(self::MAGIC));
            if (self::MAGIC !== $magic) {
                throw new RuntimeException(sprintf('%s is not an encrypted AIHM backup (bad magic). A pre-encryption `.sql.gz` dump is restored by piping it through gunzip directly.', basename($ciphertextPath)));
            }

            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (false === $header || SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES !== \strlen($header)) {
                throw new RuntimeException(sprintf('BackupCipher: %s is truncated (no stream header).', basename($ciphertextPath)));
            }

            try {
                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            } catch (SodiumException $e) {
                throw new RuntimeException(sprintf('BackupCipher: %s has an unusable stream header.', basename($ciphertextPath)), previous: $e);
            }

            $blockBytes = self::CHUNK_BYTES + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            $sawFinal = false;
            $chunkIndex = 0;

            while (!$sawFinal) {
                $block = fread($in, $blockBytes);
                if (false === $block || '' === $block) {
                    break;
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);
                if (false === $result) {
                    // Which chunk failed is the difference between two very
                    // different jobs. A wrong key cannot decrypt anything, so it
                    // fails on the first one; a stream that ran for a while and
                    // then stopped verifying is a damaged file, and the operator
                    // needs to fetch it again rather than go hunting for another
                    // key. Reporting the first message for the second case sends
                    // them looking for a lost key that was never lost.
                    throw new RuntimeException(0 === $chunkIndex ? sprintf('BackupCipher: cannot decrypt %s — wrong BACKUP_ENCRYPTION_KEY, or the file was altered in transit.', basename($ciphertextPath)) : sprintf('BackupCipher: %s is damaged or truncated at chunk %d — the key is right, the file is not. Re-fetch it from the off-host copy.', basename($ciphertextPath), $chunkIndex));
                }

                ++$chunkIndex;

                [$plaintext, $tag] = $result;

                $this->write($out, $plaintext, 'the output stream');

                $sawFinal = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag;
            }

            if (!$sawFinal) {
                throw new RuntimeException(sprintf('BackupCipher: %s ends without a final chunk — the file is truncated, and restoring it would load a partial dump.', basename($ciphertextPath)));
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * fwrite reports a short write instead of throwing, and the case that matters
     * is a full disk: ignoring the return value there produces a backup file that
     * exists, looks plausible and stops halfway.
     *
     * @param resource $handle
     */
    private function write($handle, string $bytes, string $what): void
    {
        if ('' === $bytes) {
            return;
        }

        $written = fwrite($handle, $bytes);

        if (false === $written || $written !== \strlen($bytes)) {
            throw new RuntimeException(sprintf('BackupCipher: short write to %s — out of disk space?', $what));
        }
    }
}
