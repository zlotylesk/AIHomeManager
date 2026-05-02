<?php

declare(strict_types=1);

namespace App\Tests\Integration\Articles;

use App\Tests\Support\AuthenticatedApiTrait;
use Doctrine\ORM\EntityManagerInterface;
use Redis;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ArticlesApiTest extends WebTestCase
{
    use AuthenticatedApiTrait;

    private KernelBrowser $client;
    private Redis $redis;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->authenticate($this->client);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->redis = static::getContainer()->get('app.redis');

        $conn = $em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE article_daily_picks');
        $conn->executeStatement('TRUNCATE TABLE articles');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->redis->del('articles:today');
    }

    public function testListArticlesReturnsEmptyArray(): void
    {
        $this->client->request('GET', '/api/articles');

        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true));
    }

    public function testCreateArticleReturns201(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Test Article',
            'url' => 'https://example.com/test',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('id', $data);
    }

    public function testCreateArticleWithMissingFieldsReturns422(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode(['title' => 'No URL']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testGetArticleDetailReturnsCorrectData(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Test',
            'url' => 'https://example.com/test',
            'category' => 'tech',
        ]));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('GET', "/api/articles/{$id}");

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Test', $data['title']);
        self::assertSame('https://example.com/test', $data['url']);
        self::assertSame('tech', $data['category']);
        self::assertFalse($data['isRead']);
    }

    public function testGetArticleDetailReturns404ForUnknown(): void
    {
        $this->client->request('GET', '/api/articles/00000000-0000-0000-0000-000000000000');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUpdateArticleReturns204(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Original',
            'url' => 'https://example.com/orig',
        ]));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('PUT', "/api/articles/{$id}", content: json_encode([
            'title' => 'Updated',
            'category' => 'news',
        ]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/articles/{$id}");
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('Updated', $data['title']);
        self::assertSame('news', $data['category']);
    }

    public function testDeleteArticleReturns204(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'To Delete',
            'url' => 'https://example.com/delete',
        ]));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('DELETE', "/api/articles/{$id}");
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/articles/{$id}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testMarkAsReadReturns204(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'To Read',
            'url' => 'https://example.com/read',
        ]));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/articles/{$id}/read");
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/articles/{$id}");
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertTrue($data['isRead']);
        self::assertNotNull($data['readAt']);
    }

    public function testMarkAsReadInvalidatesTodayCache(): void
    {
        $this->redis->setex('articles:today', 3600, 'some-cached-id');

        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Article',
            'url' => 'https://example.com/article',
        ]));
        $id = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/api/articles/{$id}/read");

        self::assertFalse($this->redis->get('articles:today'));
    }

    public function testTodayEndpointReturns204WhenNoArticles(): void
    {
        $this->client->request('GET', '/api/articles/today');

        self::assertResponseStatusCodeSame(204);
    }

    public function testTodayEndpointReturnsSameArticleOnRepeatCalls(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Article A',
            'url' => 'https://example.com/a',
        ]));
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Article B',
            'url' => 'https://example.com/b',
        ]));

        $this->client->request('GET', '/api/articles/today');
        self::assertResponseIsSuccessful();
        $first = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('GET', '/api/articles/today');
        $second = json_decode($this->client->getResponse()->getContent(), true);

        self::assertSame($first['id'], $second['id']);
    }

    public function testTodayEndpointReturnsNewArticleAfterMarkAsRead(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Article A',
            'url' => 'https://example.com/a',
        ]));
        json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Article B',
            'url' => 'https://example.com/b',
        ]));

        $this->client->request('GET', '/api/articles/today');
        $today = json_decode($this->client->getResponse()->getContent(), true);

        $this->client->request('POST', "/api/articles/{$today['id']}/read");

        $this->client->request('GET', '/api/articles/today');
        $newToday = json_decode($this->client->getResponse()->getContent(), true);

        if (200 === $this->client->getResponse()->getStatusCode()) {
            self::assertNotSame($today['id'], $newToday['id']);
        } else {
            self::assertResponseStatusCodeSame(204);
        }
    }

    public function testTodayEndpointCachesTtlUntilMidnight(): void
    {
        $this->client->request('POST', '/api/articles', content: json_encode([
            'title' => 'Article',
            'url' => 'https://example.com/cache-test',
        ]));

        $this->client->request('GET', '/api/articles/today');
        self::assertResponseIsSuccessful();

        $ttl = $this->redis->ttl('articles:today');
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(86400, $ttl);
    }
}
