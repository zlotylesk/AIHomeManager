<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\DeleteSeries;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use DomainException;
use Psr\Log\LoggerInterface;
use Redis;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class DeleteSeriesHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
        private Redis $redis,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteSeries $command): void
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        $seasonIds = array_keys($series->seasons());

        $this->repository->delete($series);

        $this->redis->del("series:avg:{$command->seriesId}");
        foreach ($seasonIds as $seasonId) {
            $this->redis->del("season:avg:{$seasonId}");
        }

        $this->logger->info('Series deleted', ['seriesId' => $command->seriesId]);
    }
}
