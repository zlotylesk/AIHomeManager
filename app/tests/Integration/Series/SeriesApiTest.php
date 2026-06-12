<?php

declare(strict_types=1);

namespace App\Tests\Integration\Series;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SeriesApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE series_episodes');
        $conn->executeStatement('TRUNCATE TABLE series_seasons');
        $conn->executeStatement('TRUNCATE TABLE series');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testListSeriesReturnsEmptyArray(): void
    {
        $this->client->request('GET', '/api/series');

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testCreateSeriesReturns201WithId(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'Breaking Bad']));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertNotEmpty($data['id']);
    }

    public function testCreateSeriesWithEmptyTitleReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => '']));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Title is required.', $data['error']);
    }

    public function testCreateSeriesWithMissingTitleReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSeriesWithTitleOver255CharactersReturns422(): void
    {
        // HMAI-66: VARCHAR(255) would silently truncate (or throw) — explicit 422
        // is the contract. 256-char title (str_repeat) trips the new bound; 255
        // exactly is still accepted in the next test.
        $longTitle = str_repeat('a', 256);
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => $longTitle]));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Title must be at most 255 characters.', $data['error']);
    }

    public function testCreateSeriesWithTitleExactly255CharactersAccepted(): void
    {
        $title = str_repeat('b', 255);
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => $title]));

        self::assertResponseStatusCodeSame(201);
    }

    public function testGetSeriesDetailReturnsCorrectData(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'Breaking Bad']));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('GET', "/api/series/{$id}");

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($id, $data['id']);
        self::assertSame('Breaking Bad', $data['title']);
        self::assertSame([], $data['seasons']);
        self::assertNull($data['averageRating']);
    }

    public function testGetSeriesDetailReturns404ForUnknownId(): void
    {
        $this->client->request('GET', '/api/series/non-existent-id');

        self::assertResponseStatusCodeSame(404);
    }

    public function testListSeriesReturnsCreatedSeries(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'Breaking Bad']));
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'The Wire']));

        $this->client->request('GET', '/api/series');

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(2, $data);
    }

    public function testAddSeasonReturns201WithId(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: json_encode(['number' => 1]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
        self::assertNotEmpty($data['id']);
    }

    public function testAddSeasonWithInvalidNumberReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: json_encode(['number' => -1]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAddSeasonForUnknownSeriesReturns404(): void
    {
        $this->client->request('POST', '/api/series/non-existent/seasons', content: json_encode(['number' => 1]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddEpisodeReturns201WithId(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: json_encode(['number' => 1]));
        $seasonId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: json_encode(['title' => 'Pilot']));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
    }

    public function testAddEpisodeWithMissingTitleReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: json_encode(['number' => 1]));
        $seasonId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: json_encode([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRateEpisodeReturns204AndUpdatesAverage(): void
    {
        // HMAI-43: PATCH endpoint exercises the existing AddEpisodeRating
        // command/handler with the Series aggregate's rateEpisode() method.
        // We seed an episode WITHOUT a rating, then PATCH 8 and assert the
        // GET reflects the new series average — proving the rating actually
        // hit the aggregate and was persisted (not just acknowledged).
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot']));
        $episodeId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/rating", content: (string) json_encode(['rating' => 8]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        // JSON-decoded as int when the float has no fractional part — compare
        // loosely (== ignores type). Same pattern as MusicApiTest matchScore.
        self::assertEquals(8.0, $data['averageRating']);
        self::assertSame(8, $data['seasons'][0]['episodes'][0]['rating']);
    }

    public function testRateEpisodeWithOutOfRangeRatingReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot']));
        $episodeId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/rating", content: (string) json_encode(['rating' => 11]));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Field "rating" must be an integer between 1 and 10.', $data['error']);
    }

    public function testRateEpisodeWithNonIntRatingReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot']));
        $episodeId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/rating", content: (string) json_encode(['rating' => '8']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRateEpisodeOnUnknownEpisodeReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/missing-episode-id/rating", content: (string) json_encode(['rating' => 8]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddEpisodeWithTitleOver255CharactersReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $longTitle = str_repeat('e', 256);
        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => $longTitle]));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Title must be at most 255 characters.', $data['error']);
    }

    public function testAddEpisodeForUnknownSeriesReturns404(): void
    {
        $this->client->request('POST', '/api/series/non-existent/seasons/non-existent/episodes', content: json_encode(['title' => 'Pilot']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testSeriesDetailIncludesEpisodesWithRatingsAndAverages(): void
    {
        $this->client->request('POST', '/api/series', content: json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: json_encode(['number' => 1]));
        $seasonId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: json_encode(['title' => 'Pilot', 'rating' => 8]));
        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: json_encode(['title' => 'Episode 2', 'rating' => 10]));

        $this->client->request('GET', "/api/series/{$seriesId}");

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);

        self::assertCount(1, $data['seasons']);
        self::assertCount(2, $data['seasons'][0]['episodes']);
        self::assertSame(9, $data['averageRating']);
        self::assertSame(9, $data['seasons'][0]['averageRating']);
    }

    public function testRateSeriesReturns204AndIsReflectedSeparatelyFromAverage(): void
    {
        // HMAI-179: the user's own series score is stored and returned as
        // `rating`, independent of `averageRating` (no episodes → average null).
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['rating' => 9]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(9, $data['rating']);
        self::assertNull($data['averageRating']);
    }

    public function testRateSeriesCoexistsWithEpisodeAverage(): void
    {
        // Own series rating and the episode-derived average must both survive,
        // distinct from one another.
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'rating' => 4]));

        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['rating' => 9]));
        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/rating", content: (string) json_encode(['rating' => 7]));

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(9, $data['rating']);
        self::assertEquals(4.0, $data['averageRating']);
        self::assertSame(7, $data['seasons'][0]['rating']);
        self::assertEquals(4.0, $data['seasons'][0]['averageRating']);
    }

    public function testRateSeriesWithOutOfRangeRatingReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['rating' => 11]));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Field "rating" must be an integer between 1 and 10.', $data['error']);
    }

    public function testRateSeriesForUnknownSeriesReturns404(): void
    {
        $this->client->request('PATCH', '/api/series/non-existent/rating', content: (string) json_encode(['rating' => 5]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRateSeasonReturns204AndIsReflectedSeparatelyFromAverage(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/rating", content: (string) json_encode(['rating' => 6]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(6, $data['seasons'][0]['rating']);
        self::assertNull($data['seasons'][0]['averageRating']);
    }

    public function testRateSeasonWithOutOfRangeRatingReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/rating", content: (string) json_encode(['rating' => 0]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRateSeasonOnUnknownSeasonReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/missing-season-id/rating", content: (string) json_encode(['rating' => 6]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testSetEpisodeWatchedReturns204AndGetReflectsIt(): void
    {
        [$seriesId, $seasonId, $episodeId] = $this->seedEpisode();

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/watched", content: (string) json_encode(['watched' => true]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $episode = $data['seasons'][0]['episodes'][0];
        self::assertTrue($episode['watched']);
        self::assertNotNull($episode['watchedAt']);
        // Counters surface "watched X/Y" for the UI.
        self::assertSame(1, $data['seasons'][0]['watchedCount']);
        self::assertSame(1, $data['seasons'][0]['episodeCount']);
        self::assertSame(1, $data['watchedCount']);
    }

    public function testUnsetEpisodeWatchedReturns204AndClearsFlag(): void
    {
        [$seriesId, $seasonId, $episodeId] = $this->seedEpisode();
        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/watched", content: (string) json_encode(['watched' => true]));

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/watched", content: (string) json_encode(['watched' => false]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $episode = $data['seasons'][0]['episodes'][0];
        self::assertFalse($episode['watched']);
        self::assertNull($episode['watchedAt']);
        self::assertSame(0, $data['seasons'][0]['watchedCount']);
    }

    public function testSetEpisodeWatchedWithNonBooleanReturns422(): void
    {
        [$seriesId, $seasonId, $episodeId] = $this->seedEpisode();

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/watched", content: (string) json_encode(['watched' => 'yes']));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Field "watched" must be a boolean.', $data['error']);
    }

    public function testSetEpisodeWatchedOnUnknownEpisodeReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];
        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/missing-episode-id/watched", content: (string) json_encode(['watched' => true]));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [seriesId, seasonId, episodeId]
     */
    private function seedEpisode(): array
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot']));
        $episodeId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        return [$seriesId, $seasonId, $episodeId];
    }
}
