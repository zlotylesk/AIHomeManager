<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Podcasts\Application;

use App\Module\Podcasts\Domain\Entity\PodcastListeningSession;
use App\Module\Podcasts\Domain\Repository\PodcastListeningSessionRepositoryInterface;
use LogicException;

final class InMemoryPodcastListeningSessionRepository implements PodcastListeningSessionRepositoryInterface
{
    /** @var array<string, PodcastListeningSession> */
    public array $saved = [];

    /** Counts writes, not rows — the handler must not re-save an unchanged session. */
    public int $writes = 0;

    public function save(PodcastListeningSession $session): void
    {
        $this->saved[$session->id()] = $session;
        ++$this->writes;
    }

    public function findByDedupHash(string $dedupHash): ?PodcastListeningSession
    {
        foreach ($this->saved as $session) {
            if ($session->dedupHash() === $dedupHash) {
                return $session;
            }
        }

        return null;
    }

    public function only(): PodcastListeningSession
    {
        return array_first($this->saved) ?? throw new LogicException('No listening session was saved.');
    }
}
