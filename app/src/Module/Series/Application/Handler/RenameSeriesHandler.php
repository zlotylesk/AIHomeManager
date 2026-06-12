<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\RenameSeries;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RenameSeriesHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RenameSeries $command): void
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        $series->rename($command->title);
        $this->repository->save($series);

        $this->logger->info('Series renamed', ['seriesId' => $command->seriesId]);
    }
}
