<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\ImportRatingsFromTrakt;
use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Port\RatingsProviderInterface;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use App\Module\Series\Domain\ValueObject\Rating;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Maps the user's Trakt ratings (1–10) onto the Series aggregate's own ratings
 * (HMAI-220), chained after the watched-shows import.
 *
 * Skip-if-missing: a rating for a show/season/episode the watched import never
 * materialised is ignored — ratings only enrich what the user actually watched
 * (≥1 episode). Idempotent: a rating equal to the stored one writes nothing, so a
 * re-run on unchanged Trakt data persists nothing.
 *
 * Episode ratings deliberately do NOT dispatch the EpisodeRated event here: a bulk
 * import would flood the bus with thousands of Redis-recompute events, and the read
 * model derives averages live (SeriesController::serializeDTO), so the skipped
 * events change nothing the user sees.
 *
 * @phpstan-import-type TraktRatings from RatingsProviderInterface
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ImportRatingsFromTraktHandler
{
    public function __construct(
        private RatingsProviderInterface $provider,
        private SeriesRepositoryInterface $repository,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportRatingsFromTrakt $command): void
    {
        $ratings = $this->provider->fetchRatings();

        $changedSeries = 0;
        foreach ($this->groupByShow($ratings) as $traktId => $bucket) {
            // PHP coerces numeric-string array keys to int — cast back for the repo.
            $series = $this->repository->findByTraktId((string) $traktId);
            if (null === $series) {
                // Skip-if-missing: a rated show the watched import never created.
                continue;
            }

            if ($this->applyRatings($series, $bucket)) {
                $this->repository->save($series);
                ++$changedSeries;
            }
        }

        $this->logger->info('Trakt ratings imported', [
            'shows' => \count($ratings['shows']),
            'seasons' => \count($ratings['seasons']),
            'episodes' => \count($ratings['episodes']),
            'changedSeries' => $changedSeries,
        ]);
    }

    /**
     * Collapses the three flat Trakt rating lists into one bucket per show, keyed
     * by trakt id — so each series is loaded and saved at most once.
     *
     * @param TraktRatings $ratings
     *
     * @return array<int|string, array{show: int|null, seasons: array<int, int>, episodes: array<int, array<int, int>>}>
     */
    private function groupByShow(array $ratings): array
    {
        $byShow = [];

        foreach ($ratings['shows'] as $row) {
            $key = (string) $row['traktId'];
            $byShow[$key] ??= ['show' => null, 'seasons' => [], 'episodes' => []];
            $byShow[$key]['show'] = $row['rating'];
        }
        foreach ($ratings['seasons'] as $row) {
            $key = (string) $row['traktId'];
            $byShow[$key] ??= ['show' => null, 'seasons' => [], 'episodes' => []];
            $byShow[$key]['seasons'][$row['seasonNumber']] = $row['rating'];
        }
        foreach ($ratings['episodes'] as $row) {
            $key = (string) $row['traktId'];
            $byShow[$key] ??= ['show' => null, 'seasons' => [], 'episodes' => []];
            $byShow[$key]['episodes'][$row['seasonNumber']][$row['episodeNumber']] = $row['rating'];
        }

        return $byShow;
    }

    /**
     * @param array{show: int|null, seasons: array<int, int>, episodes: array<int, array<int, int>>} $bucket
     *
     * @return bool whether anything changed (drives the save)
     */
    private function applyRatings(Series $series, array $bucket): bool
    {
        $changed = false;

        if (null !== $bucket['show'] && !$this->sameRating($series->rating(), $bucket['show'])) {
            $series->rate(new Rating($bucket['show']));
            $changed = true;
        }

        foreach ($bucket['seasons'] as $seasonNumber => $rating) {
            $season = $this->findSeasonByNumber($series, $seasonNumber);
            if (null === $season || $this->sameRating($season->rating(), $rating)) {
                continue;
            }
            $series->rateSeason($season->id(), new Rating($rating));
            $changed = true;
        }

        foreach ($bucket['episodes'] as $seasonNumber => $episodes) {
            $season = $this->findSeasonByNumber($series, $seasonNumber);
            if (null === $season) {
                continue;
            }
            foreach ($episodes as $episodeNumber => $rating) {
                $episode = $this->findEpisodeByNumber($season, $episodeNumber);
                if (null === $episode || $this->sameRating($episode->rating(), $rating)) {
                    continue;
                }
                $series->rateEpisode($season->id(), $episode->id(), new Rating($rating));
                $changed = true;
            }
        }

        return $changed;
    }

    private function sameRating(?Rating $current, int $incoming): bool
    {
        return null !== $current && $current->value() === $incoming;
    }

    private function findSeasonByNumber(Series $series, int $number): ?Season
    {
        foreach ($series->seasons() as $season) {
            if ($season->number() === $number) {
                return $season;
            }
        }

        return null;
    }

    private function findEpisodeByNumber(Season $season, int $number): ?Episode
    {
        foreach ($season->episodes() as $episode) {
            if ($episode->number() === $number) {
                return $episode;
            }
        }

        return null;
    }
}
