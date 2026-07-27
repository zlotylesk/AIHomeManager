<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\AddSeason;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Exception\SeasonNumberAlreadyTaken;
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

    /**
     * Season-number uniqueness within a series is enforced HERE, not in
     * Series::addSeason() — the repository's hydration path
     * (attachSeasonsAndEpisodes()) reuses that same method to rebuild the
     * aggregate from already-persisted rows, so an invariant on the entity
     * would blow up reading an existing show if a pre-fix duplicate ever
     * slipped in. The handler only guards the write path, mirroring the
     * check renumberSeason() already does on the aggregate for renumbering.
     */
    public function __invoke(AddSeason $command): string
    {
        $series = $this->repository->findById($command->seriesId);
        if (null === $series) {
            throw new DomainException(sprintf('Series "%s" not found.', $command->seriesId));
        }

        foreach ($series->seasons() as $season) {
            if ($season->number() === $command->number) {
                throw new SeasonNumberAlreadyTaken(sprintf('Season number %d is already used in series "%s".', $command->number, $command->seriesId));
            }
        }

        $id = Uuid::v4()->toRfc4122();

        $series->addSeason(new Season(id: $id, seriesId: $command->seriesId, number: $command->number));
        $this->repository->save($series);

        return $id;
    }
}
