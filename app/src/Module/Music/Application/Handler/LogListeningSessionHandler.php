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
            playedAt: $command->playedAt->setTimezone(new DateTimeZone('UTC')),
            source: $command->source,
            playCount: $command->playCount,
        );

        if ($this->repository->existsByDedupHash($session->dedupHash())) {
            return;
        }

        $this->repository->save($session);
    }
}
