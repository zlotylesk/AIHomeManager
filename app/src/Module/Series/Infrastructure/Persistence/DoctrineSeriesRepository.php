<?php

declare(strict_types=1);

namespace App\Module\Series\Infrastructure\Persistence;

use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineSeriesRepository implements SeriesRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Series $series): void
    {
        $this->entityManager->persist($series);

        foreach ($series->seasons() as $season) {
            $this->entityManager->persist($season);

            foreach ($season->episodes() as $episode) {
                $this->entityManager->persist($episode);
            }
        }

        $this->entityManager->flush();
    }

    public function findById(string $id): ?Series
    {
        $series = $this->entityManager->find(Series::class, $id);

        if (null === $series) {
            return null;
        }

        $this->loadSeasons($series);

        return $series;
    }

    /** @return Series[] */
    public function findAll(): array
    {
        $allSeries = $this->entityManager->createQuery(
            'SELECT s FROM '.Series::class.' s'
        )->getResult();

        foreach ($allSeries as $series) {
            $this->loadSeasons($series);
        }

        return $allSeries;
    }

    private function loadSeasons(Series $series): void
    {
        $seasons = $this->entityManager->createQuery(
            'SELECT s FROM '.Season::class.' s WHERE s.seriesId = :seriesId'
        )
            ->setParameter('seriesId', $series->id())
            ->getResult();

        foreach ($seasons as $season) {
            $this->loadEpisodes($season);
            $series->addSeason($season);
        }
    }

    private function loadEpisodes(Season $season): void
    {
        $episodes = $this->entityManager->createQuery(
            'SELECT e FROM '.Episode::class.' e WHERE e.seasonId = :seasonId'
        )
            ->setParameter('seasonId', $season->id())
            ->getResult();

        foreach ($episodes as $episode) {
            $season->addEpisode($episode);
        }
    }
}
