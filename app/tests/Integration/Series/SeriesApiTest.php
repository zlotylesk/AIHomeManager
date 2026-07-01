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
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testCreateSeriesReturns201WithId(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));

        self::assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('id', $data);
        self::assertNotEmpty($data['id']);
    }

    public function testCreateSeriesWithEmptyTitleReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => '']));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Title is required.', $data['error']);
    }

    public function testCreateSeriesWithMissingTitleReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSeriesWithTitleOver255CharactersReturns422(): void
    {
        $longTitle = str_repeat('a', 256);
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => $longTitle]));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
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
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $id = $this->jsonResponse($this->client)['id'];

        $this->client->request('GET', "/api/series/{$id}");

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
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
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'The Wire']));

        $this->client->request('GET', '/api/series');

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
        self::assertCount(2, $data);
    }

    public function testAddSeasonReturns201WithId(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));

        self::assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('id', $data);
        self::assertNotEmpty($data['id']);
    }

    public function testAddSeasonWithInvalidNumberReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => -1]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAddSeasonForUnknownSeriesReturns404(): void
    {
        $this->client->request('POST', '/api/series/non-existent/seasons', content: (string) json_encode(['number' => 1]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddEpisodeReturns201WithId(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));

        self::assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('id', $data);
    }

    public function testAddEpisodeWithMissingTitleReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode([]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRateEpisodeReturns204AndUpdatesAverage(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));
        $episodeId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/rating", content: (string) json_encode(['rating' => 8]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);

        self::assertEquals(8.0, $data['averageRating']);
        self::assertSame(8, $data['seasons'][0]['episodes'][0]['rating']);
    }

    public function testRateEpisodeWithOutOfRangeRatingReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));
        $episodeId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/rating", content: (string) json_encode(['rating' => 11]));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Field "rating" must be an integer between 1 and 10.', $data['error']);
    }

    public function testRateEpisodeWithNonIntRatingReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));
        $episodeId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/rating", content: (string) json_encode(['rating' => '8']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRateEpisodeOnUnknownEpisodeReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/missing-episode-id/rating", content: (string) json_encode(['rating' => 8]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddEpisodeWithTitleOver255CharactersReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $longTitle = str_repeat('e', 256);
        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => $longTitle]));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Title must be at most 255 characters.', $data['error']);
    }

    public function testAddEpisodeForUnknownSeriesReturns404(): void
    {
        $this->client->request('POST', '/api/series/non-existent/seasons/non-existent/episodes', content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testSeriesDetailIncludesEpisodesWithRatingsAndAverages(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1, 'rating' => 8]));
        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Episode 2', 'number' => 2, 'rating' => 10]));

        $this->client->request('GET', "/api/series/{$seriesId}");

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);

        self::assertCount(1, $data['seasons']);
        self::assertCount(2, $data['seasons'][0]['episodes']);
        self::assertSame(9, $data['averageRating']);
        self::assertSame(9, $data['seasons'][0]['averageRating']);
    }

    public function testRateSeriesReturns204AndIsReflectedSeparatelyFromAverage(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['rating' => 9]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame(9, $data['rating']);
        self::assertNull($data['averageRating']);
    }

    public function testRateSeriesCoexistsWithEpisodeAverage(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1, 'rating' => 4]));

        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['rating' => 9]));
        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/rating", content: (string) json_encode(['rating' => 7]));

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);

        self::assertSame(9, $data['rating']);
        self::assertEquals(4.0, $data['averageRating']);
        self::assertSame(7, $data['seasons'][0]['rating']);
        self::assertEquals(4.0, $data['seasons'][0]['averageRating']);
    }

    public function testRateSeriesWithOutOfRangeRatingReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['rating' => 11]));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Field "rating" must be an integer between 1 and 10, or null to clear.', $data['error']);
    }

    public function testRateSeriesForUnknownSeriesReturns404(): void
    {
        $this->client->request('PATCH', '/api/series/non-existent/rating', content: (string) json_encode(['rating' => 5]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRateSeasonReturns204AndIsReflectedSeparatelyFromAverage(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/rating", content: (string) json_encode(['rating' => 6]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame(6, $data['seasons'][0]['rating']);
        self::assertNull($data['seasons'][0]['averageRating']);
    }

    public function testRateSeasonWithOutOfRangeRatingReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/rating", content: (string) json_encode(['rating' => 0]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRateSeasonOnUnknownSeasonReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/missing-season-id/rating", content: (string) json_encode(['rating' => 6]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRenameSeriesReturns204AndUpdatesTitle(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Braking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}", content: (string) json_encode(['title' => 'Breaking Bad']));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame('Breaking Bad', $data['title']);
    }

    public function testRenameSeriesWithEmptyTitleReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}", content: (string) json_encode(['title' => '   ']));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Title is required.', $data['error']);
    }

    public function testRenameSeriesForUnknownSeriesReturns404(): void
    {
        $this->client->request('PATCH', '/api/series/non-existent', content: (string) json_encode(['title' => 'Breaking Bad']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRenumberSeasonReturns204AndUpdatesNumber(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}", content: (string) json_encode(['number' => 3]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame(3, $data['seasons'][0]['number']);
    }

    public function testRenumberSeasonWithInvalidNumberReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}", content: (string) json_encode(['number' => 0]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRenumberSeasonToNumberAlreadyUsedReturns409(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId1 = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 2]));

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId1}", content: (string) json_encode(['number' => 2]));

        self::assertResponseStatusCodeSame(409);
    }

    public function testRenumberSeasonForUnknownSeasonReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/missing-season-id", content: (string) json_encode(['number' => 2]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRenameEpisodeReturns204AndUpdatesTitle(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilto', 'number' => 1]));
        $episodeId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}", content: (string) json_encode(['title' => 'Pilot']));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame('Pilot', $data['seasons'][0]['episodes'][0]['title']);
    }

    public function testRenameEpisodeWithEmptyTitleReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));
        $episodeId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}", content: (string) json_encode(['title' => '']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testRenameEpisodeOnUnknownEpisodeReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/missing-episode-id", content: (string) json_encode(['title' => 'Pilot']));

        self::assertResponseStatusCodeSame(404);
    }

    public function testClearSeriesRatingReturns204AndNullsRating(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['rating' => 9]));
        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['rating' => null]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertNull($data['rating']);
    }

    public function testClearSeasonRatingReturns204AndNullsRating(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/rating", content: (string) json_encode(['rating' => 6]));
        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/rating", content: (string) json_encode(['rating' => null]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertNull($data['seasons'][0]['rating']);
    }

    public function testRateSeriesWithMissingRatingFieldReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/rating", content: (string) json_encode(['foo' => 'bar']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testClearSeriesRatingForUnknownSeriesReturns404(): void
    {
        $this->client->request('PATCH', '/api/series/non-existent/rating', content: (string) json_encode(['rating' => null]));

        self::assertResponseStatusCodeSame(404);
    }

    public function testSetEpisodeWatchedReturns204AndGetReflectsIt(): void
    {
        [$seriesId, $seasonId, $episodeId] = $this->seedEpisode();

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}/watched", content: (string) json_encode(['watched' => true]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        $episode = $data['seasons'][0]['episodes'][0];
        self::assertTrue($episode['watched']);
        self::assertNotNull($episode['watchedAt']);

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
        $data = $this->jsonResponse($this->client);
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
        $data = $this->jsonResponse($this->client);
        self::assertSame('Field "watched" must be a boolean.', $data['error']);
    }

    public function testSetEpisodeWatchedOnUnknownEpisodeReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];
        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/missing-episode-id/watched", content: (string) json_encode(['watched' => true]));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{0: string, 1: string, 2: string} [seriesId, seasonId, episodeId]
     */
    private function seedEpisode(): array
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));
        $episodeId = $this->jsonResponse($this->client)['id'];

        return [$seriesId, $seasonId, $episodeId];
    }

    public function testDeleteSeriesReturns204AndCascadesSeasonsAndEpisodes(): void
    {
        [$seriesId] = $this->seedSeriesWithEpisode();

        $this->client->request('DELETE', "/api/series/{$seriesId}");
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, $this->countRows('series_seasons'));
        self::assertSame(0, $this->countRows('series_episodes'));
    }

    public function testDeleteUnknownSeriesReturns404(): void
    {
        $this->client->request('DELETE', '/api/series/non-existent');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteSeasonReturns204AndCascadesEpisodes(): void
    {
        [$seriesId, $seasonId] = $this->seedSeriesWithEpisode();

        $this->client->request('DELETE', "/api/series/{$seriesId}/seasons/{$seasonId}");
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
        self::assertSame([], $data['seasons']);

        self::assertSame(0, $this->countRows('series_episodes'));
    }

    public function testDeleteSeasonForUnknownSeriesReturns404(): void
    {
        $this->client->request('DELETE', '/api/series/non-existent/seasons/whatever');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteUnknownSeasonReturns404(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('DELETE', "/api/series/{$seriesId}/seasons/missing-season-id");

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteEpisodeReturns204AndRemovesItFromDetail(): void
    {
        [$seriesId, $seasonId, $episodeId] = $this->seedSeriesWithEpisode();

        $this->client->request('DELETE', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/{$episodeId}");
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame([], $data['seasons'][0]['episodes']);
        self::assertSame(0, $this->countRows('series_episodes'));
    }

    public function testDeleteEpisodeForUnknownSeriesReturns404(): void
    {
        $this->client->request('DELETE', '/api/series/non-existent/seasons/whatever/episodes/whatever');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteUnknownEpisodeReturns404(): void
    {
        [$seriesId, $seasonId] = $this->seedSeriesWithEpisode();

        $this->client->request('DELETE', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes/missing-episode-id");

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{0: string, 1: string, 2: string} [seriesId, seasonId, episodeId] */
    private function seedSeriesWithEpisode(): array
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));
        $episodeId = $this->jsonResponse($this->client)['id'];

        return [$seriesId, $seasonId, $episodeId];
    }

    private function countRows(string $table): int
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return match ($table) {
            'series_seasons' => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM series_seasons'),
            'series_episodes' => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM series_episodes'),
            default => (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM series'),
        };
    }

    public function testAddEpisodeWithoutNumberReturns422(): void
    {
        [$seriesId, $seasonId] = $this->seedSeriesWithSeason();

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot']));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Episode number must be a positive integer.', $data['error']);
    }

    public function testAddEpisodeWithDuplicateNumberInSeasonReturns422(): void
    {
        [$seriesId, $seasonId] = $this->seedSeriesWithSeason();

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Pilot', 'number' => 1]));
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Another', 'number' => 1]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testGetSeriesDetailReturnsEpisodeNumbersSortedByNumber(): void
    {
        [$seriesId, $seasonId] = $this->seedSeriesWithSeason();

        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'Third', 'number' => 3]));
        $this->client->request('POST', "/api/series/{$seriesId}/seasons/{$seasonId}/episodes", content: (string) json_encode(['title' => 'First', 'number' => 1]));

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);

        $episodes = $data['seasons'][0]['episodes'];
        self::assertSame([1, 3], array_column($episodes, 'number'));
        self::assertSame(['First', 'Third'], array_column($episodes, 'title'));
    }

    public function testCreateSeriesWithMetadataAndGetReturnsIt(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Breaking Bad',
            'coverUrl' => 'https://image.tmdb.org/t/p/w500/poster.jpg',
            'year' => 2008,
            'status' => 'ended',
            'description' => 'A high-school chemistry teacher turns to cooking meth.',
        ]));
        self::assertResponseStatusCodeSame(201);
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);

        self::assertSame('https://image.tmdb.org/t/p/w500/poster.jpg', $data['coverUrl']);
        self::assertSame(2008, $data['year']);
        self::assertSame('ended', $data['status']);
        self::assertSame('A high-school chemistry teacher turns to cooking meth.', $data['description']);
    }

    public function testCreateSeriesWithoutMetadataReturnsNullFields(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);

        self::assertNull($data['coverUrl']);
        self::assertNull($data['year']);
        self::assertNull($data['status']);
        self::assertNull($data['description']);
    }

    public function testCreateSeriesWithInvalidCoverUrlReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Breaking Bad',
            'coverUrl' => 'not-a-url',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSeriesWithDisallowedSchemeCoverUrlReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Breaking Bad',
            'coverUrl' => 'javascript:alert(1)',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSeriesWithOutOfRangeYearReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Breaking Bad',
            'year' => 1800,
        ]));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertStringContainsString('year', $data['error']);
    }

    public function testCreateSeriesWithNonIntYearReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Breaking Bad',
            'year' => '2008',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateSeriesWithUnknownStatusReturns422(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Breaking Bad',
            'status' => 'cancelled',
        ]));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertSame('Field "status" must be one of: ongoing, ended.', $data['error']);
    }

    public function testPatchUpdatesSeriesMetadata(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}", content: (string) json_encode([
            'title' => 'Breaking Bad',
            'coverUrl' => 'https://example.com/cover.jpg',
            'year' => 2008,
            'status' => 'ongoing',
            'description' => 'Updated synopsis.',
        ]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame('https://example.com/cover.jpg', $data['coverUrl']);
        self::assertSame(2008, $data['year']);
        self::assertSame('ongoing', $data['status']);
        self::assertSame('Updated synopsis.', $data['description']);
    }

    public function testPatchTitleOnlyLeavesMetadataUntouched(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Braking Bad',
            'coverUrl' => 'https://example.com/cover.jpg',
            'year' => 2008,
            'status' => 'ended',
        ]));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}", content: (string) json_encode(['title' => 'Breaking Bad']));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame('Breaking Bad', $data['title']);
        self::assertSame('https://example.com/cover.jpg', $data['coverUrl']);
        self::assertSame(2008, $data['year']);
        self::assertSame('ended', $data['status']);
    }

    public function testPatchCanClearMetadataWithNulls(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Breaking Bad',
            'coverUrl' => 'https://example.com/cover.jpg',
            'year' => 2008,
            'status' => 'ended',
            'description' => 'Synopsis.',
        ]));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}", content: (string) json_encode([
            'title' => 'Breaking Bad',
            'coverUrl' => null,
            'year' => null,
            'status' => null,
            'description' => null,
        ]));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertNull($data['coverUrl']);
        self::assertNull($data['year']);
        self::assertNull($data['status']);
        self::assertNull($data['description']);
    }

    public function testPatchWithInvalidMetadataReturns422AndDoesNotRename(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('PATCH', "/api/series/{$seriesId}", content: (string) json_encode([
            'title' => 'Changed Title',
            'status' => 'bogus',
        ]));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', "/api/series/{$seriesId}");
        $data = $this->jsonResponse($this->client);
        self::assertSame('Breaking Bad', $data['title']);
    }

    public function testListReturnsMetadata(): void
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode([
            'title' => 'Breaking Bad',
            'coverUrl' => 'https://example.com/cover.jpg',
            'year' => 2008,
            'status' => 'ended',
        ]));

        $this->client->request('GET', '/api/series');
        $data = $this->jsonResponse($this->client);

        self::assertCount(1, $data);
        self::assertSame('https://example.com/cover.jpg', $data[0]['coverUrl']);
        self::assertSame(2008, $data[0]['year']);
        self::assertSame('ended', $data[0]['status']);
    }

    /** @return array{0: string, 1: string} [seriesId, seasonId] */
    private function seedSeriesWithSeason(): array
    {
        $this->client->request('POST', '/api/series', content: (string) json_encode(['title' => 'Breaking Bad']));
        $seriesId = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/series/{$seriesId}/seasons", content: (string) json_encode(['number' => 1]));
        $seasonId = $this->jsonResponse($this->client)['id'];

        return [$seriesId, $seasonId];
    }
}
