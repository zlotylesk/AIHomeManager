<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Handler;

use App\Module\Music\Application\Command\LogListeningSession;
use App\Module\Music\Domain\Entity\ListeningSession;
use App\Module\Music\Domain\Repository\ListeningSessionRepositoryInterface;
use App\Module\Music\Domain\ValueObject\AlbumArtist;
use App\Module\Music\Domain\ValueObject\AlbumTitle;
use DateTimeZone;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class LogListeningSessionHandler
{
    public function __construct(
        private ListeningSessionRepositoryInterface $repository,
    ) {
    }

    public function __invoke(LogListeningSession $command): void
    {
        $session = new ListeningSession(
            id: Uuid::v4()->toRfc4122(),
            artist: new AlbumArtist($command->artist),
            title: new AlbumTitle($command->title),
            // Normalize to UTC so storage, dedup hashing, and the read path all
            // agree regardless of the timezone the caller passed in (HMAI-144).
            playedAt: $command->playedAt->setTimezone(new DateTimeZone('UTC')),
            source: $command->source,
            playCount: $command->playCount,
        );

        // Idempotent: repeated Last.fm polls re-deliver the same scrobbles, so a
        // duplicate dedup hash is an expected no-op, not an error.
        if ($this->repository->existsByDedupHash($session->dedupHash())) {
            return;
        }

        $this->repository->save($session);
    }
}
