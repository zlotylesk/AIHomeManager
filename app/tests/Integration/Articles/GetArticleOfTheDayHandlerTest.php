<?php

declare(strict_types=1);

namespace App\Tests\Integration\Articles;

use App\Module\Articles\Application\Query\GetArticleOfTheDay;
use App\Module\Articles\Application\QueryHandler\GetArticleOfTheDayHandler;
use App\Module\Articles\Domain\Entity\ArticleDailyPick;
use App\Module\Articles\Domain\Repository\ArticleDailyPickRepositoryInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Redis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class GetArticleOfTheDayHandlerTest extends KernelTestCase
{
    private GetArticleOfTheDayHandler $handler;
    private Connection $connection;
    private Redis $redis;
    private ArticleDailyPickRepositoryInterface $pickRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->handler = $container->get(GetArticleOfTheDayHandler::class);
        $this->connection = $container->get(EntityManagerInterface::class)->getConnection();
        $this->redis = $container->get('app.redis');
        $this->pickRepository = $container->get(ArticleDailyPickRepositoryInterface::class);

        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $this->connection->executeStatement('TRUNCATE TABLE article_daily_picks');
        $this->connection->executeStatement('TRUNCATE TABLE articles');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->redis->del('articles:today');
    }

    public function testReturnsNullWhenNoArticles(): void
    {
        self::assertNull(($this->handler)(new GetArticleOfTheDay()));
    }

    public function testReturnsUnreadArticleAndCachesIt(): void
    {
        $this->insertArticle('a-1', 'How Make Works', 'https://example.com/make');

        $result = ($this->handler)(new GetArticleOfTheDay());

        self::assertNotNull($result);
        self::assertSame('a-1', $result->id);
        self::assertSame('a-1', $this->redis->get('articles:today'));
        self::assertSame(['a-1'], $this->pickRepository->findRecentlyPickedIds(1));
    }

    public function testReturnsCachedPickOnRepeatCall(): void
    {
        $this->insertArticle('a-1', 'First Pick', 'https://example.com/1');
        $this->insertArticle('a-2', 'Other Article', 'https://example.com/2');

        $first = ($this->handler)(new GetArticleOfTheDay());
        $second = ($this->handler)(new GetArticleOfTheDay());

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($first->id, $second->id);

        self::assertCount(1, $this->pickRepository->findRecentlyPickedIds(1));
    }

    public function testExcludesRecentlyPickedIds(): void
    {
        $this->insertArticle('a-old', 'Already Picked', 'https://example.com/old');
        $this->insertArticle('a-new', 'Fresh Article', 'https://example.com/new');

        $this->pickRepository->save(new ArticleDailyPick(
            id: Uuid::v4()->toRfc4122(),
            articleId: 'a-old',
            pickedAt: new DateTimeImmutable('-1 day'),
        ));

        $result = ($this->handler)(new GetArticleOfTheDay());

        self::assertNotNull($result);
        self::assertSame('a-new', $result->id);
    }

    public function testPrefersConfiguredCategory(): void
    {
        $this->insertArticle('a-tech', 'Tech Story', 'https://example.com/tech', category: 'tech');
        $this->insertArticle('a-other', 'Other Story', 'https://example.com/other', category: 'news');

        $handler = new GetArticleOfTheDayHandler(
            $this->connection,
            $this->pickRepository,
            $this->redis,
            preferredCategory: 'tech',
        );

        $result = $handler(new GetArticleOfTheDay());

        self::assertNotNull($result);
        self::assertSame('a-tech', $result->id);
    }

    private function insertArticle(string $id, string $title, string $url, ?string $category = null): void
    {
        $this->connection->executeStatement(
            'INSERT INTO articles (id, title, url, category, estimated_read_time, added_at, is_read)
             VALUES (:id, :title, :url, :category, NULL, NOW(), 0)',
            [
                'id' => $id,
                'title' => $title,
                'url' => $url,
                'category' => $category,
            ]
        );
    }
}
