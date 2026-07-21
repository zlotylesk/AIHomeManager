<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Application\Handler;

use App\Module\Podcasts\Application\Command\LogPodcastListeningSession;
use App\Module\Podcasts\Domain\Entity\Episode;
use App\Module\Podcasts\Domain\Entity\Podcast;
use App\Module\Podcasts\Domain\Entity\PodcastListeningSession;
use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use App\Module\Podcasts\Domain\Repository\EpisodeRepositoryInterface;
use App\Module\Podcasts\Domain\Repository\PodcastListeningSessionRepositoryInterface;
use App\Module\Podcasts\Domain\Repository\PodcastRepositoryInterface;
use App\Module\Podcasts\Domain\ValueObject\Title;
use App\Shared\Domain\ValueObject\CoverUrl;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Persist one observed listen, materializing whatever catalog it refers to.
 *
 * Materialize rather than skip — the decision the ticket asks to record. Music
 * has no catalog aggregates at all, so its session simply stores the artist and
 * title as strings; here the history points at a Podcast and an Episode by id,
 * and skipping an unknown show would leave rows referencing nothing the UI can
 * render. The source hands back the full show and episode metadata alongside the
 * listen, so there is nothing to go and fetch: the show is minted on first sight,
 * exactly as the Trakt import mints a Movie (HMAI-290).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class LogPodcastListeningSessionHandler
{
    public function __construct(
        private PodcastRepositoryInterface $podcasts,
        private EpisodeRepositoryInterface $episodes,
        private PodcastListeningSessionRepositoryInterface $sessions,
    ) {
    }

    public function __invoke(LogPodcastListeningSession $command): void
    {
        $listened = $command->listened;

        $podcast = $this->materializePodcast($listened);
        $episode = $this->materializeEpisode($listened, $podcast->id());

        $dedupHash = PodcastListeningSession::computeDedupHash(
            $podcast->id(),
            $episode->id(),
            $listened->listenedAt,
        );

        $existing = $this->sessions->findByDedupHash($dedupHash);

        if (null !== $existing) {
            // Same episode, same day: one session. Write only when this
            // observation actually moved the listener forward, so a poll that
            // sees nothing new costs no write at all.
            if ($existing->observeProgress($listened->progress)) {
                $this->sessions->save($existing);
            }

            return;
        }

        $this->sessions->save(new PodcastListeningSession(
            id: Uuid::v4()->toRfc4122(),
            podcastId: $podcast->id(),
            episodeId: $episode->id(),
            listenedAt: $listened->listenedAt,
            progress: $listened->progress,
            createdAt: new DateTimeImmutable(),
        ));
    }

    private function materializePodcast(ListenedEpisode $listened): Podcast
    {
        $podcast = $this->podcasts->findByExternalId($listened->podcastExternalId);
        $coverUrl = $this->validCoverUrl($listened->coverUrl);

        if (null === $podcast) {
            $podcast = new Podcast(
                Uuid::v4()->toRfc4122(),
                new Title($listened->podcastTitle),
                new DateTimeImmutable(),
            );
            $podcast->linkExternal($listened->podcastExternalId);
            $podcast->updateMetadata($listened->publisher, $coverUrl, null);
            $this->podcasts->save($podcast);

            return $podcast;
        }

        // The catalog mirrors the source, so a publisher or cover the source
        // changed must follow here — but only a real change earns a write. A
        // poll re-reports every started episode, so saving unconditionally
        // would flush once per listen per run for no reason at all.
        if ($podcast->publisher() === $listened->publisher && $podcast->coverUrl() === $coverUrl) {
            return $podcast;
        }

        // Description is carried over rather than cleared: the listening source
        // does not report one, so a full replace with null would wipe whatever
        // put it there.
        $podcast->updateMetadata($listened->publisher, $coverUrl, $podcast->description());
        $this->podcasts->save($podcast);

        return $podcast;
    }

    private function materializeEpisode(ListenedEpisode $listened, string $podcastId): Episode
    {
        $episode = $this->episodes->findByExternalId($listened->episodeExternalId);

        if (null === $episode) {
            $episode = new Episode(
                Uuid::v4()->toRfc4122(),
                $podcastId,
                new Title($listened->episodeTitle),
                new DateTimeImmutable(),
            );
            $episode->linkExternal($listened->episodeExternalId);
            $episode->updateMetadata($listened->publishedAt, $listened->durationMs);
            $this->episodes->save($episode);

            return $episode;
        }

        // `==` on purpose: two DateTimeImmutable instances for the same moment
        // are never identical, and both sides may legitimately be null.
        if ($episode->publishedAt() == $listened->publishedAt && $episode->durationMs() === $listened->durationMs) {
            return $episode;
        }

        $episode->updateMetadata($listened->publishedAt, $listened->durationMs);
        $this->episodes->save($episode);

        return $episode;
    }

    /**
     * Artwork is cosmetic. A source that hands back a URL the shared VO rejects
     * should cost us the image, not the listening record — refusing to log what
     * the user heard because the cover art is malformed would be the wrong trade.
     */
    private function validCoverUrl(?string $coverUrl): ?string
    {
        if (null === $coverUrl) {
            return null;
        }

        try {
            return new CoverUrl($coverUrl)->value();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
