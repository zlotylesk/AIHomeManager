<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Infrastructure\External;

use App\Module\Podcasts\Domain\Port\PodcastListeningHistoryInterface;
use App\Module\Podcasts\Domain\ReadModel\ListenedEpisode;
use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads the user's podcast listening from the Spotify Web API (one-directional
 * Spotify → AIHM), behind the rate-limited HTTP client.
 *
 * Why this shape — Spotify exposes NO timestamped podcast listen history. Its
 * /me/player/recently-played endpoint covers tracks only and silently excludes
 * episodes, so there is nothing to page through. What it does expose is, per
 * episode, a `resume_point` ({fully_played, resume_position_ms}) whenever the
 * token carries the user-read-playback-position scope. Listens are therefore
 * DERIVED: an episode with a non-zero resume position, or one marked fully
 * played, has been listened to. That is a state, not an event — which is why
 * {@see ListenedEpisode::$listenedAt} means "no later than" and is stamped with
 * the observation time, upgraded to a real moment only for whatever the user
 * happens to be playing right now.
 *
 * Cost: resume points only come attached to episodes, and episodes are only
 * listable per show, so one poll is 1 + N requests across the saved shows. Only
 * the newest page of each show is read (see EPISODE_PAGE_SIZE) — reading every
 * back-catalogue episode of every subscription would turn a routine poll into
 * thousands of calls to catch a rare listen deep in an archive.
 */
final readonly class SpotifyPodcastApiClient implements PodcastListeningHistoryInterface
{
    private const string BASE_URL = 'https://api.spotify.com/v1';
    private const string PROVIDER = 'spotify';

    /** Spotify's hard maximum for both saved-shows and show-episodes pages. */
    private const int PAGE_SIZE = 50;

    /**
     * Newest episodes read per show. Spotify returns episodes newest-first, and
     * listening concentrates on recent ones.
     */
    private const int EPISODE_PAGE_SIZE = 50;

    /** Safety stop for the saved-shows pagination, so a bad `next` cannot spin. */
    private const int MAX_SHOW_PAGES = 20;

    public function __construct(
        private HttpClientInterface $httpClient,
        private SpotifyTokenProvider $tokenProvider,
        #[Autowire(service: 'monolog.logger.series')]
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function fetchListenedEpisodes(): array
    {
        $accessToken = $this->tokenProvider->getValidAccessToken();

        if (null === $accessToken) {
            throw new RuntimeException('Spotify account not connected.');
        }

        // The listening data itself comes first and is allowed to fail loudly;
        // the now-playing enrichment runs afterwards and degrades quietly. Doing
        // it the other way round would let the optional call's error handling
        // swallow an outage that should have aborted the whole read.
        $started = [];

        foreach ($this->fetchSavedShows($accessToken) as $show) {
            // One broken show must not cost the whole sweep: a subscription can
            // 404 because the episode list is region-restricted or the show was
            // pulled from the catalogue, and letting that propagate would stop
            // the user's listening from syncing at all until they unsubscribe.
            // A genuine outage still aborts loudly — the saved-shows call above
            // runs first and is not caught.
            try {
                $episodes = $this->fetchShowEpisodes($accessToken, $show['id']);
            } catch (RuntimeException $e) {
                $this->logger->warning('Spotify show episodes unavailable, skipping show', [
                    'provider' => self::PROVIDER,
                    'show_id' => $show['id'],
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($episodes as $episode) {
                if (!$episode['progress']->isStarted()) {
                    continue;
                }

                $started[] = ['show' => $show, 'episode' => $episode];
            }
        }

        $observedAt = new DateTimeImmutable();
        $nowPlaying = $this->fetchNowPlaying($accessToken);

        $listened = [];

        foreach ($started as $entry) {
            $show = $entry['show'];
            $episode = $entry['episode'];

            $listenedAt = $observedAt;
            if (null !== $nowPlaying && $nowPlaying['episodeId'] === $episode['id']) {
                $listenedAt = $nowPlaying['at'];
            }

            $listened[] = new ListenedEpisode(
                $show['id'],
                $show['title'],
                $episode['id'],
                $episode['title'],
                $listenedAt,
                $episode['progress'],
                $show['publisher'],
                $show['coverUrl'],
                $episode['publishedAt'],
                $episode['durationMs'],
            );
        }

        return $listened;
    }

    /**
     * The user's saved shows (subscriptions), fully paginated.
     *
     * @return list<array{id: string, title: string, publisher: ?string, coverUrl: ?string}>
     */
    private function fetchSavedShows(string $accessToken): array
    {
        $shows = [];
        $offset = 0;

        for ($page = 0; $page < self::MAX_SHOW_PAGES; ++$page) {
            $data = $this->get($accessToken, '/me/shows', [
                'limit' => (string) self::PAGE_SIZE,
                'offset' => (string) $offset,
            ]);

            $items = $data['items'] ?? null;
            if (!is_array($items) || [] === $items) {
                break;
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $show = $item['show'] ?? null;
                if (!is_array($show)) {
                    continue;
                }

                $id = $this->stringOrNull($show['id'] ?? null);
                $title = $this->stringOrNull($show['name'] ?? null);
                if (null === $id || null === $title) {
                    continue;
                }

                $shows[] = [
                    'id' => $id,
                    'title' => $title,
                    'publisher' => $this->stringOrNull($show['publisher'] ?? null),
                    'coverUrl' => $this->firstImageUrl($show['images'] ?? null),
                ];
            }

            if (!is_string($data['next'] ?? null)) {
                break;
            }

            $offset += self::PAGE_SIZE;
        }

        return $shows;
    }

    /**
     * The newest page of a show's episodes, each with its resume point.
     *
     * @return list<array{id: string, title: string, progress: ListeningProgress, publishedAt: ?DateTimeImmutable, durationMs: ?int}>
     */
    private function fetchShowEpisodes(string $accessToken, string $showId): array
    {
        $data = $this->get($accessToken, '/shows/'.$showId.'/episodes', [
            'limit' => (string) self::EPISODE_PAGE_SIZE,
            'offset' => '0',
        ]);

        $items = $data['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $episodes = [];

        foreach ($items as $item) {
            // Spotify pads pages with nulls for episodes unavailable in the market.
            if (!is_array($item)) {
                continue;
            }

            $id = $this->stringOrNull($item['id'] ?? null);
            $title = $this->stringOrNull($item['name'] ?? null);
            if (null === $id || null === $title) {
                continue;
            }

            $episodes[] = [
                'id' => $id,
                'title' => $title,
                'progress' => $this->parseResumePoint($item['resume_point'] ?? null),
                'publishedAt' => $this->parseReleaseDate($item),
                'durationMs' => $this->nonNegativeIntOrNull($item['duration_ms'] ?? null),
            ];
        }

        return $episodes;
    }

    /**
     * What the user is playing right now, when it is an episode. This is the one
     * place Spotify reports an actual moment (`timestamp`, ms since epoch), so it
     * is the only listen we can date precisely.
     *
     * A failure here is not fatal: without it every listen simply falls back to
     * the observation time, which is the documented contract anyway.
     *
     * @return array{episodeId: string, at: DateTimeImmutable}|null
     */
    private function fetchNowPlaying(string $accessToken): ?array
    {
        try {
            $data = $this->get($accessToken, '/me/player/currently-playing', [
                'additional_types' => 'episode',
            ]);
        } catch (RuntimeException $e) {
            $this->logger->info('Spotify currently-playing unavailable, falling back to observation time', [
                'provider' => self::PROVIDER,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        $item = $data['item'] ?? null;
        if (!is_array($item)) {
            return null;
        }

        $episodeId = $this->stringOrNull($item['id'] ?? null);
        if (null === $episodeId) {
            return null;
        }

        $timestamp = $data['timestamp'] ?? null;
        if (!is_int($timestamp) || $timestamp <= 0) {
            return null;
        }

        return [
            'episodeId' => $episodeId,
            'at' => new DateTimeImmutable('@'.intdiv($timestamp, 1000)),
        ];
    }

    /**
     * Authenticated GET against the Spotify Web API, decoded to an array.
     *
     * @param array<string, string> $query
     *
     * @return array<array-key, mixed>
     */
    private function get(string $accessToken, string $path, array $query = []): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Accept' => 'application/json',
            ],
        ];

        if ([] !== $query) {
            $options['query'] = $query;
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.$path, $options);
            $status = $response->getStatusCode();

            // Spotify answers 204 when there is nothing to report (most often on
            // currently-playing with the player idle). The body is empty, so
            // decoding it would throw — an empty payload is the honest answer.
            if (Response::HTTP_NO_CONTENT === $status) {
                return [];
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException(sprintf('Spotify API returned HTTP %d for %s.', $status, $path));
            }

            $data = $response->toArray();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException('Spotify API unavailable.', 0, $e);
        }

        return $data;
    }

    /**
     * @return ListeningProgress the episode's resume point, or "not started"
     *                           when the scope is missing or the field is absent
     */
    private function parseResumePoint(mixed $resumePoint): ListeningProgress
    {
        if (!is_array($resumePoint)) {
            return ListeningProgress::notStarted();
        }

        $position = $resumePoint['resume_position_ms'] ?? null;
        $fullyPlayed = $resumePoint['fully_played'] ?? null;

        return new ListeningProgress(
            is_int($position) && $position > 0 ? $position : 0,
            true === $fullyPlayed,
        );
    }

    /**
     * Spotify dates an episode with a precision flag — `release_date` may be a
     * bare year or year-month. Only a full date names a real day, so anything
     * coarser is reported as unknown rather than silently rounded to January 1st.
     *
     * @param array<array-key, mixed> $episode
     */
    private function parseReleaseDate(array $episode): ?DateTimeImmutable
    {
        if ('day' !== ($episode['release_date_precision'] ?? null)) {
            return null;
        }

        $releaseDate = $this->stringOrNull($episode['release_date'] ?? null);
        if (null === $releaseDate) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $releaseDate);

        return false === $parsed ? null : $parsed;
    }

    private function firstImageUrl(mixed $images): ?string
    {
        if (!is_array($images)) {
            return null;
        }

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $url = $this->stringOrNull($image['url'] ?? null);
            if (null !== $url) {
                return $url;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    private function nonNegativeIntOrNull(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
