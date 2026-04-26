<?php

declare(strict_types=1);

namespace App\Tests\Integration\Series;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SeriesApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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
}
