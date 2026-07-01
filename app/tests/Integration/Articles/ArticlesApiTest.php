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
        self::assertSame([], $this->jsonResponse($this->client));
    }

    public function testCreateArticleReturns201(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Test Article',
            'url' => 'https://example.com/test',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = $this->jsonResponse($this->client);
        self::assertArrayHasKey('id', $data);
    }

    public function testCreateArticleWithMissingFieldsReturns422(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode(['title' => 'No URL']));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateArticleRejectsJavascriptUrlAs422(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'XSS Attempt',
            'url' => 'javascript:alert(1)',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateArticleRejectsJavascriptUrlWithCommentAs422(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'XSS via comment',
            'url' => 'javascript://example.com/%0Aalert(1)',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateArticleRejectsDataSchemeUrlAs422(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Data scheme',
            'url' => 'data:text/html,<script>alert(1)</script>',
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateArticleWithInvalidUrlReturnsGenericErrorMessage(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'XSS Attempt',
            'url' => 'javascript:alert(1)',
        ]));

        self::assertResponseStatusCodeSame(422);
        $body = (string) $this->client->getResponse()->getContent();
        $data = json_decode($body, true);
        self::assertSame('Invalid article data.', $data['error']);
        self::assertStringNotContainsString('scheme', strtolower($body));
        self::assertStringNotContainsString('javascript', strtolower($body));
    }

    public function testGetArticleDetailReturnsCorrectData(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Test',
            'url' => 'https://example.com/test',
            'category' => 'tech',
        ]));
        $id = $this->jsonResponse($this->client)['id'];

        $this->client->request('GET', "/api/articles/{$id}");

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse($this->client);
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
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Original',
            'url' => 'https://example.com/orig',
        ]));
        $id = $this->jsonResponse($this->client)['id'];

        $this->client->request('PUT', "/api/articles/{$id}", content: (string) json_encode([
            'title' => 'Updated',
            'category' => 'news',
        ]));

        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/articles/{$id}");
        $data = $this->jsonResponse($this->client);
        self::assertSame('Updated', $data['title']);
        self::assertSame('news', $data['category']);
    }

    public function testUpdateArticleRejectsBlankCategoryWith422(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Original',
            'url' => 'https://example.com/blank-cat',
        ]));
        $id = $this->jsonResponse($this->client)['id'];

        $this->client->request('PUT', "/api/articles/{$id}", content: (string) json_encode([
            'title' => 'Updated',
            'category' => '   ',
        ]));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertStringContainsString('category cannot be a blank string', $data['error']);
    }

    public function testUpdateArticleRejectsZeroEstimatedReadTimeWith422(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Original',
            'url' => 'https://example.com/zero-ert',
        ]));
        $id = $this->jsonResponse($this->client)['id'];

        $this->client->request('PUT', "/api/articles/{$id}", content: (string) json_encode([
            'title' => 'Updated',
            'estimated_read_time' => 0,
        ]));

        self::assertResponseStatusCodeSame(422);
        $data = $this->jsonResponse($this->client);
        self::assertStringContainsString('estimatedReadTime must be a positive integer', $data['error']);
    }

    public function testDeleteArticleReturns204(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'To Delete',
            'url' => 'https://example.com/delete',
        ]));
        $id = $this->jsonResponse($this->client)['id'];

        $this->client->request('DELETE', "/api/articles/{$id}");
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/articles/{$id}");
        self::assertResponseStatusCodeSame(404);
    }

    public function testMarkAsReadReturns204(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'To Read',
            'url' => 'https://example.com/read',
        ]));
        $id = $this->jsonResponse($this->client)['id'];

        $this->client->request('POST', "/api/articles/{$id}/read");
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', "/api/articles/{$id}");
        $data = $this->jsonResponse($this->client);
        self::assertTrue($data['isRead']);
        self::assertNotNull($data['readAt']);
    }

    public function testMarkAsReadInvalidatesTodayCache(): void
    {
        $this->redis->setex('articles:today', 3600, 'some-cached-id');

        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Article',
            'url' => 'https://example.com/article',
        ]));
        $id = $this->jsonResponse($this->client)['id'];

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
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Article A',
            'url' => 'https://example.com/a',
        ]));
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Article B',
            'url' => 'https://example.com/b',
        ]));

        $this->client->request('GET', '/api/articles/today');
        self::assertResponseIsSuccessful();
        $first = $this->jsonResponse($this->client);

        $this->client->request('GET', '/api/articles/today');
        $second = $this->jsonResponse($this->client);

        self::assertSame($first['id'], $second['id']);
    }

    public function testTodayEndpointReturnsNewArticleAfterMarkAsRead(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Article A',
            'url' => 'https://example.com/a',
        ]));
        $this->jsonResponse($this->client);

        $this->client->request('POST', '/api/articles', content: (string) json_encode([
            'title' => 'Article B',
            'url' => 'https://example.com/b',
        ]));

        $this->client->request('GET', '/api/articles/today');
        $today = $this->jsonResponse($this->client);

        $this->client->request('POST', "/api/articles/{$today['id']}/read");

        $this->client->request('GET', '/api/articles/today');
        $newToday = $this->jsonResponse($this->client);

        if (200 === $this->client->getResponse()->getStatusCode()) {
            self::assertNotSame($today['id'], $newToday['id']);
        } else {
            self::assertResponseStatusCodeSame(204);
        }
    }

    public function testTodayEndpointCachesTtlUntilMidnight(): void
    {
        $this->client->request('POST', '/api/articles', content: (string) json_encode([
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
