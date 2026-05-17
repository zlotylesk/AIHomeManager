<?php

declare(strict_types=1);

namespace App\Tests\Integration\DataFixtures;

use App\DataFixtures\ArticleFixtures;
use App\DataFixtures\BookFixtures;
use App\DataFixtures\SeriesFixtures;
use App\DataFixtures\TaskFixtures;
use App\Module\Articles\Domain\Repository\ArticleRepositoryInterface;
use App\Module\Books\Domain\Enum\BookStatus;
use App\Module\Books\Domain\Repository\BookRepositoryInterface;
use App\Module\Series\Domain\Repository\SeriesRepositoryInterface;
use App\Module\Tasks\Domain\Repository\TaskRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FixturesLoadTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ObjectManager $manager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->manager = $this->em;
        $this->truncateTables();
    }

    private function truncateTables(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['series_episodes', 'series_seasons', 'series', 'book_reading_sessions', 'books', 'tasks', 'article_daily_picks', 'articles'] as $table) {
            $conn->executeStatement('TRUNCATE TABLE '.$table);
        }
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testSeriesFixturesSeedThreeSeriesEachWithTwoSeasons(): void
    {
        // HMAI-39: smoke-tests that the fixture's data shape matches what
        // `/api/series` consumers expect — three series, every season nested
        // with episodes, every episode rated. If a future refactor accidentally
        // shifts the seed, this catches it before `make fixtures` lies.
        $repo = static::getContainer()->get(SeriesRepositoryInterface::class);

        new SeriesFixtures($repo)->load($this->manager);
        $this->em->clear();

        $all = $repo->findAll();
        self::assertCount(3, $all);

        foreach ($all as $series) {
            self::assertCount(2, $series->seasons(), $series->title().' should have 2 seasons');
            foreach ($series->seasons() as $season) {
                self::assertCount(5, $season->episodes(), $series->title().' season '.$season->number().' should have 5 episodes');
                foreach ($season->episodes() as $episode) {
                    self::assertNotNull($episode->rating(), 'Every fixture episode must be rated');
                }
            }
        }
    }

    public function testBookFixturesSeedFiveBooksAcrossAllStatuses(): void
    {
        // The seed must exercise every BookStatus value so the UI status
        // dropdown has at least one example per state out of the box.
        $repo = static::getContainer()->get(BookRepositoryInterface::class);

        new BookFixtures($repo)->load($this->manager);
        $this->em->clear();

        $all = $repo->findAll();
        self::assertCount(5, $all);

        $statuses = array_map(static fn ($b) => $b->status()->value, $all);
        self::assertContains(BookStatus::TO_READ->value, $statuses);
        self::assertContains(BookStatus::READING->value, $statuses);
        self::assertContains(BookStatus::COMPLETED->value, $statuses);
    }

    public function testArticleFixturesSeedTenArticlesWithThreeRead(): void
    {
        // 7 unread / 3 read split ensures `/api/articles/today` finds a candidate
        // immediately AND that the read-history view has examples to render.
        $repo = static::getContainer()->get(ArticleRepositoryInterface::class);

        new ArticleFixtures($repo)->load($this->manager);
        $this->em->clear();

        $count = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM articles');
        self::assertSame(10, $count);

        $readCount = (int) $this->em->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM articles WHERE is_read = 1');
        self::assertSame(3, $readCount);
    }

    public function testTaskFixturesSeedFourTasksAcrossYesterdayAndToday(): void
    {
        // The time-report view needs at least one task per day in the relevant
        // window. Seeds 3 today + 1 yesterday so the day-grouping logic has
        // multiple rows to bucket.
        $repo = static::getContainer()->get(TaskRepositoryInterface::class);

        new TaskFixtures($repo)->load($this->manager);
        $this->em->clear();

        self::assertCount(4, $repo->findAll());
    }
}
