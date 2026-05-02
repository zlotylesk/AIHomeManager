<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\AddSeason;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use DomainException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class AddSeasonHandler
{
    public function __construct(
        private SeriesRepositoryInterface $repository,
    ) {
    }

    public function __invoke(AddSeason $command): string
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        $id = Uuid::v4()->toRfc4122();

        $series->addSeason(new Season(id: $id, seriesId: $command->seriesId, number: $command->number));
        $this->repository->save($series);

        return $id;
    }
}
