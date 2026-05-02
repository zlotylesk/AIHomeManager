<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\AddEpisode;
use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use App\Module\Series\Domain\ValueObject\Rating;
use DomainException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddEpisodeHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
        #[Target('event.bus')]
        private MessageBusInterface $eventBus,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AddEpisode $command): string
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        $id = Uuid::v4()->toRfc4122();
        $series->addEpisode($command->seasonId, new Episode($id, $command->seasonId, $command->title));

        if (null !== $command->rating) {
            $series->rateEpisode($command->seasonId, $id, new Rating($command->rating));
        }

        $this->repository->save($series);

        foreach ($series->releaseEvents() as $event) {
            $this->eventBus->dispatch($event);
        }

        $this->logger->info('Episode added', ['id' => $id, 'seriesId' => $command->seriesId, 'seasonId' => $command->seasonId]);

        return $id;
    }
}
