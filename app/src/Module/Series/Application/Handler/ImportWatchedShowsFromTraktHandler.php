<?php

declare(strict_types=1);

namespace App\Module\Series\Application\Handler;

use App\Module\Series\Application\Command\ImportWatchedShowsFromTrakt;
use App\Module\Series\Domain\Entity\Episode;
use App\Module\Series\Domain\Entity\Season;
use App\Module\Series\Domain\Entity\Series;
use App\Module\Series\Domain\Port\WatchedShowsProviderInterface;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Maps the user's watched shows from Trakt onto the Series aggregate (HMAI-183).
 *
 * Idempotent by construction: series are deduplicated on their Trakt id, seasons
 * on their number within the series, episodes on their number within the season.
 * A re-run on unchanged Trakt data writes nothing. Trakt only ships episode
 * numbers + play timestamps here (no titles), so freshly imported episodes get a
 * "Episode N" placeholder the user can later rename.
 *
 * @phpstan-import-type WatchedShow from WatchedShowsProviderInterface
 * @phpstan-import-type WatchedSeason from WatchedShowsProviderInterface
 * @phpstan-import-type WatchedEpisode from WatchedShowsProviderInterface
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ImportWatchedShowsFromTraktHandler
{
    public function __construct(
        private WatchedShowsProviderInterface $provider,
        private SeriesRepositoryInterface $repository,
        #[Target('series')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportWatchedShowsFromTrakt $command): void
    {
        // Lets the provider's RuntimeException ("Trakt account not connected.",
        // "… client ID not configured.", "… API unavailable.") propagate — the
        // worker retries/DLQs it and layer 5 (HMAI-184) handles UX.
        $shows = $this->provider->fetchWatchedShows();

        $changedShows = 0;
        foreach ($shows as $show) {
            if ($this->importShow($show)) {
                ++$changedShows;
            }
        }

        $this->logger->info('Trakt watched shows imported', [
            'shows' => \count($shows),
            'changed' => $changedShows,
        ]);
    }

    /**
     * @param WatchedShow $show
     *
     * @return bool whether the import created or updated anything (drives the save)
     */
    private function importShow(array $show): bool
    {
        // ≥1 watched episode is the import criterion — never materialise an empty
        // show/season the user has not actually started.
        if (!$this->hasWatchedEpisode($show['seasons'])) {
            return false;
        }

        $traktId = (string) $show['traktId'];
        $series = $this->repository->findByTraktId($traktId);

        $changed = false;
        if (null === $series) {
            $series = new Series(Uuid::v4()->toRfc4122(), '' !== $show['title'] ? $show['title'] : 'Untitled');
            $series->linkTrakt($traktId);
            $changed = true;
        }

        foreach ($show['seasons'] as $seasonData) {
            if ([] === $seasonData['episodes']) {
                continue;
            }
            $changed = $this->importSeason($series, $seasonData) || $changed;
        }

        if ($changed) {
            $this->repository->save($series);
        }

        return $changed;
    }

    /**
     * @param WatchedSeason $seasonData
     */
    private function importSeason(Series $series, array $seasonData): bool
    {
        $season = $this->findSeasonByNumber($series, $seasonData['number']);

        $changed = false;
        if (null === $season) {
            $season = new Season(Uuid::v4()->toRfc4122(), $series->id(), $seasonData['number']);
            $series->addSeason($season);
            $changed = true;
        }

        foreach ($seasonData['episodes'] as $episodeData) {
            $changed = $this->importEpisode($series, $season, $episodeData) || $changed;
        }

        return $changed;
    }

    /**
     * @param WatchedEpisode $episodeData
     */
    private function importEpisode(Series $series, Season $season, array $episodeData): bool
    {
        $number = $episodeData['number'];
        $watchedAt = $this->parseWatchedAt($episodeData['lastWatchedAt']);
        $episode = $this->findEpisodeByNumber($season, $number);

        if (null === $episode) {
            $episode = new Episode(Uuid::v4()->toRfc4122(), $season->id(), sprintf('Episode %d', $number), $number);
            $series->addEpisode($season->id(), $episode);
            $series->setEpisodeWatched($season->id(), $episode->id(), true, $watchedAt);

            return true;
        }

        // Existing episode (manual or prior import) Trakt now reports watched —
        // flip it but keep the row. Already-watched episodes stay untouched so a
        // re-run neither duplicates nor rewrites a previously recorded date.
        if (!$episode->isWatched()) {
            $series->setEpisodeWatched($season->id(), $episode->id(), true, $watchedAt);

            return true;
        }

        return false;
    }

    /**
     * @param list<WatchedSeason> $seasons
     */
    private function hasWatchedEpisode(array $seasons): bool
    {
        return array_any($seasons, fn ($season) => [] !== $season['episodes']);
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

    private function parseWatchedAt(?string $value): ?DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            // Malformed Trakt timestamp — fall back to "now" (markWatched default).
            return null;
        }
    }
}
