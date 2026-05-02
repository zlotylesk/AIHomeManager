<?php

declare(strict_types=1);

namespace App\Tests\Integration\Series;

use App\Module\Series\Domain\Event\EpisodeRated;
use App\Module\Series\Infrastructure\Messenger\EpisodeRatedHandler;
use Doctrine\ORM\EntityManagerInterface;
use Redis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class EpisodeRatedHandlerTest extends KernelTestCase
{
    private EpisodeRatedHandler $handler;
    private Redis $redis;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->handler = $container->get(EpisodeRatedHandler::class);
        $this->redis = $container->get('app.redis');

        $em = $container->get(EntityManagerInterface::class);
        $conn = $em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        $conn->executeStatement('TRUNCATE TABLE series_episodes');
        $conn->executeStatement('TRUNCATE TABLE series_seasons');
        $conn->executeStatement('TRUNCATE TABLE series');
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->redis->del('series:avg:series-1');
        $this->redis->del('season:avg:season-1');
    }

    public function testHandlerWritesAveragesToRedis(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $em->executeStatement(
            "INSERT INTO series (id, title, created_at) VALUES ('series-1', 'Breaking Bad', NOW())"
        );
        $em->executeStatement(
            "INSERT INTO series_seasons (id, series_id, number) VALUES ('season-1', 'series-1', 1)"
        );
        $em->executeStatement(
            "INSERT INTO series_episodes (id, season_id, title, rating_value) VALUES ('ep-1', 'season-1', 'Pilot', 8)"
        );
        $em->executeStatement(
            "INSERT INTO series_episodes (id, season_id, title, rating_value) VALUES ('ep-2', 'season-1', 'Episode 2', 10)"
        );

        ($this->handler)(new EpisodeRated('series-1', 'season-1', 'ep-2', 10));

        self::assertSame('9', $this->redis->get('season:avg:season-1'));
        self::assertSame('9', $this->redis->get('series:avg:series-1'));
    }

    public function testHandlerStoresRoundedAverage(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $em->executeStatement(
            "INSERT INTO series (id, title, created_at) VALUES ('series-1', 'Breaking Bad', NOW())"
        );
        $em->executeStatement(
            "INSERT INTO series_seasons (id, series_id, number) VALUES ('season-1', 'series-1', 1)"
        );
        $em->executeStatement(
            "INSERT INTO series_episodes (id, season_id, title, rating_value) VALUES ('ep-1', 'season-1', 'Pilot', 7)"
        );
        $em->executeStatement(
            "INSERT INTO series_episodes (id, season_id, title, rating_value) VALUES ('ep-2', 'season-1', 'Ep2', 8)"
        );
        $em->executeStatement(
            "INSERT INTO series_episodes (id, season_id, title, rating_value) VALUES ('ep-3', 'season-1', 'Ep3', 9)"
        );

        ($this->handler)(new EpisodeRated('series-1', 'season-1', 'ep-3', 9));

        self::assertSame('8', $this->redis->get('season:avg:season-1'));
    }

    public function testHandlerDoesNotWriteWhenNoRatings(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $em->executeStatement(
            "INSERT INTO series (id, title, created_at) VALUES ('series-1', 'Breaking Bad', NOW())"
        );
        $em->executeStatement(
            "INSERT INTO series_seasons (id, series_id, number) VALUES ('season-1', 'series-1', 1)"
        );
        $em->executeStatement(
            "INSERT INTO series_episodes (id, season_id, title, rating_value) VALUES ('ep-1', 'season-1', 'Pilot', NULL)"
        );

        ($this->handler)(new EpisodeRated('series-1', 'season-1', 'ep-1', 5));

        self::assertFalse($this->redis->get('season:avg:season-1'));
        self::assertFalse($this->redis->get('series:avg:series-1'));
    }
}
