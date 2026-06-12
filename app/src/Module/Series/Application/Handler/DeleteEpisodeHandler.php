<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\DeleteEpisode;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use DomainException;
use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteEpisodeHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
        private Redis $redis,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteEpisode $command): void
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        // Throws DomainException (→ 404) when the season or episode is unknown.
        $episode = $series->removeEpisode($command->seasonId, $command->episodeId);

        $this->repository->deleteEpisode($episode);

        // Removing an episode shifts both averages, so invalidate both caches.
        $this->redis->del("season:avg:{$command->seasonId}");
        $this->redis->del("series:avg:{$command->seriesId}");

        $this->logger->info('Episode deleted', [
            'seriesId' => $command->seriesId,
            'seasonId' => $command->seasonId,
            'episodeId' => $command->episodeId,
        ]);
    }
}
