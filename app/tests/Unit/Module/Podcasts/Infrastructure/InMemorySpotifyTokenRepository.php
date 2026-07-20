<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Infrastructure;

use App\Module\Podcasts\Infrastructure\Persistence\SpotifyTokenRepositoryInterface;

/**
 * Test double for the Spotify token store. A hand-written fake rather than a
 * mock, because these tests care about what was *written back* after a refresh —
 * recording the saves keeps that assertion readable.
 */
final class InMemorySpotifyTokenRepository implements SpotifyTokenRepositoryInterface
{
    /** @var list<array<string, mixed>> */
    public array $saved = [];

    /**
     * @param array<string, mixed>|null $stored
     */
    public function __construct(private ?array $stored = null)
    {
    }

    public function get(): ?array
    {
        return $this->stored;
    }

    public function save(array $token): void
    {
        $this->saved[] = $token;
        $this->stored = $token;
    }
}
