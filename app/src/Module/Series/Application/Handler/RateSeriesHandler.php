<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\RateSeries;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use App\Module\Series\Domain\ValueObject\Rating;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RateSeriesHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RateSeries $command): void
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        $series->rate(new Rating($command->rating));

        $this->repository->save($series);

        $this->logger->info('Series rated', [
            'seriesId' => $command->seriesId,
            'rating' => $command->rating,
        ]);
    }
}
