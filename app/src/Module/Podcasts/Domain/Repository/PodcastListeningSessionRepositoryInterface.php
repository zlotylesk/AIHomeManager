<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\Repository;

use App\Module\Podcasts\Domain\Entity\PodcastListeningSession;

interface PodcastListeningSessionRepositoryInterface
{
    public function save(PodcastListeningSession $session): void;

    /**
     * The idempotency lookup. Unlike Music's existsByDedupHash it returns the
     * record rather than a boolean, because a repeated observation of the same
     * day may still carry advanced progress worth folding in (see
     * PodcastListeningSession::observeProgress).
     */
    public function findByDedupHash(string $dedupHash): ?PodcastListeningSession;
}
