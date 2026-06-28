<?php

declare(strict_types=1);

namespace App\Shared\Security;

/**
 * Shared-kernel contract for symmetric encryption of OAuth tokens at rest.
 *
 * Lets each module's Infrastructure depend on a stable cipher abstraction
 * instead of the concrete glue helper {@see \App\Security\TokenCipher}, keeping
 * the hexagonal boundaries airtight (Infrastructure → Shared, never → Glue).
 */
interface TokenCipherInterface
{
    public function encrypt(string $plaintext): string;

    public function decrypt(string $encoded): string;
}
