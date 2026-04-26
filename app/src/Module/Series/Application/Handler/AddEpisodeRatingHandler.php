<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\AddEpisodeRating;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use App\Module\Series\Domain\ValueObject\Rating;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddEpisodeRatingHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
        #[Target('event.bus')] private MessageBusInterface $eventBus,
        #[Target('series')] private LoggerInterface $logger,
    ) {}

    public function __invoke(AddEpisodeRating $command): void
    {
        $series = $this->repository->findById($command->seriesId);
        if ($series === null) {
            throw new \DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        $series->rateEpisode(
            seasonId: $command->seasonId,
            episodeId: $command->episodeId,
            rating: new Rating($command->rating),
        );

        $this->repository->save($series);

        foreach ($series->releaseEvents() as $event) {
            $this->eventBus->dispatch($event);
        }

        $this->logger->info('Episode rated', [
            'seriesId' => $command->seriesId,
            'episodeId' => $command->episodeId,
            'rating' => $command->rating,
        ]);
    }
}