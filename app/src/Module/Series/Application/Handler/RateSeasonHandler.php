<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\RateSeason;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use App\Module\Series\Domain\ValueObject\Rating;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RateSeasonHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RateSeason $command): void
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        if (null === $command->rating) {
            $series->clearSeasonRating($command->seasonId);
        } else {
            $series->rateSeason($command->seasonId, new Rating($command->rating));
        }

        $this->repository->save($series);

        $this->logger->info(null === $command->rating ? 'Season rating cleared' : 'Season rated', [
            'seriesId' => $command->seriesId,
            'seasonId' => $command->seasonId,
            'rating' => $command->rating,
        ]);
    }
}
