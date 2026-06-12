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

        $this->attachSeasonsAndEpisodes([$series]);

        return $series;
    }

    public function findByTraktId(string $traktId): ?Series
    {
        $series = $this->entityManager->getRepository(Series::class)
            ->findOneBy(['traktId' => $traktId]);

        if (null === $series) {
            return null;
        }

        $this->attachSeasonsAndEpisodes([$series]);

        return $series;
    }

    public function delete(Series $series): void
    {
        // No ORM cascade is mapped (the aggregate persists each entity manually
        // via string FKs), so deletions are issued explicitly. The aggregate was
        // hydrated with all seasons + episodes by findById, so removing them here
        // leaves no orphan rows.
        foreach ($series->seasons() as $season) {
            foreach ($season->episodes() as $episode) {
                $this->entityManager->remove($episode);
            }
            $this->entityManager->remove($season);
        }

        $this->entityManager->remove($series);
        $this->entityManager->flush();
    }

    public function deleteSeason(Season $season): void
    {
        foreach ($season->episodes() as $episode) {
            $this->entityManager->remove($episode);
        }

        $this->entityManager->remove($season);
        $this->entityManager->flush();
    }

    public function deleteEpisode(Episode $episode): void
    {
        $this->entityManager->remove($episode);
        $this->entityManager->flush();
    }

    /** @return Series[] */
    public function findAll(): array
    {
        /** @var Series[] $allSeries */
        $allSeries = $this->entityManager->createQuery(
            'SELECT s FROM '.Series::class.' s'
        )->getResult();

        $this->attachSeasonsAndEpisodes($allSeries);

        return $allSeries;
    }

    /**
     * Bulk-loads seasons (one query) and episodes (one query) for the given series and
     * attaches them in PHP. Replaces the previous per-series + per-season fetches that
     * produced 1 + N + N*M queries for a list of N series with M seasons each.
     *
     * @param Series[] $seriesList
     */
    private function attachSeasonsAndEpisodes(array $seriesList): void
    {
        if ([] === $seriesList) {
            return;
        }

        $seriesIds = array_map(static fn (Series $s): string => $s->id(), $seriesList);

        /** @var Season[] $seasons */
        $seasons = $this->entityManager->createQuery(
            'SELECT s FROM '.Season::class.' s WHERE s.seriesId IN (:seriesIds)'
        )
            ->setParameter('seriesIds', $seriesIds)
            ->getResult();

        if ([] === $seasons) {
            return;
        }

        $seasonIds = array_map(static fn (Season $s): string => $s->id(), $seasons);

        /** @var Episode[] $episodes */
        $episodes = $this->entityManager->createQuery(
            'SELECT e FROM '.Episode::class.' e WHERE e.seasonId IN (:seasonIds)'
        )
            ->setParameter('seasonIds', $seasonIds)
            ->getResult();

        /** @var array<string, Episode[]> $episodesBySeasonId */
        $episodesBySeasonId = [];
        foreach ($episodes as $episode) {
            $episodesBySeasonId[$episode->seasonId()][] = $episode;
        }

        /** @var array<string, Season[]> $seasonsBySeriesId */
        $seasonsBySeriesId = [];
        foreach ($seasons as $season) {
            foreach ($episodesBySeasonId[$season->id()] ?? [] as $episode) {
                $season->addEpisode($episode);
            }
            $seasonsBySeriesId[$season->seriesId()][] = $season;
        }

        foreach ($seriesList as $series) {
            foreach ($seasonsBySeriesId[$series->id()] ?? [] as $season) {
                $series->addSeason($season);
            }
        }
    }
}
