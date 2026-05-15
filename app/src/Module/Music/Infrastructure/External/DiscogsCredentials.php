<?php

declare(strict_types=1);

namespace App\Module\Music\Infrastructure\External;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Wraps the Discogs OAuth1 consumer credentials with a debug-safe representation.
 *
 * Storing the secret on a string ctor argument means `debug:container --show-arguments`
 * or any `var_dump` of a service instance would print it in plaintext. This VO masks
 * the secret in __debugInfo() (covers var_dump and Symfony's VarDumper, which the
 * container debugger uses) and marks the ctor arg as #[SensitiveParameter] so a
 * thrown stack trace at instantiation time does not leak the value either.
 */
final readonly class DiscogsCredentials
{
    public function __construct(
        public string $consumerKey,
        #[SensitiveParameter]
        public string $consumerSecret,
    ) {
        if ('' === trim($consumerKey)) {
            throw new InvalidArgumentException('Discogs consumer key cannot be empty.');
        }
        if ('' === trim($consumerSecret)) {
            throw new InvalidArgumentException('Discogs consumer secret cannot be empty.');
        }
    }

    /**
     * @return array{consumerKey: string, consumerSecret: string}
     */
    public function __debugInfo(): array
    {
        return [
            'consumerKey' => $this->consumerKey,
            'consumerSecret' => '***REDACTED***',
        ];
    }
}
