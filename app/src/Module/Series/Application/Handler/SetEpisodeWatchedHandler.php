<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\SetEpisodeWatched;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SetEpisodeWatchedHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SetEpisodeWatched $command): void
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        $series->setEpisodeWatched(
            seasonId: $command->seasonId,
            episodeId: $command->episodeId,
            watched: $command->watched,
        );

        $this->repository->save($series);

        $this->logger->info('Episode watched flag set', [
            'seriesId' => $command->seriesId,
            'episodeId' => $command->episodeId,
            'watched' => $command->watched,
        ]);
    }
}
