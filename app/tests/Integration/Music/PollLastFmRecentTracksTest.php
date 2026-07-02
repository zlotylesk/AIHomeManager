<?php

declare(strict_types=1);

namespace App\Tests\Integration\Music;

use App\Module\Music\Application\Command\PollLastFmRecentTracks;
use App\Module\Music\Application\Handler\PollLastFmRecentTracksHandler;
use App\Module\Music\Domain\Port\MusicListeningHistoryInterface;
use App\Module\Music\Domain\ReadModel\RecentTrack;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class PollLastFmRecentTracksTest extends KernelTestCase
{
    private Connection $connection;
    private MusicListeningHistoryInterface&MockObject $lastFm;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection->executeStatement('TRUNCATE TABLE music_listening_sessions');
        $this->lastFm = $this->createMock(MusicListeningHistoryInterface::class);
    }

    public function testPollPersistsRecentTracks(): void
    {
        $this->installLastFmStub([
            new RecentTrack('Pink Floyd', 'The Wall', new DateTimeImmutable('2026-05-20 10:00:00', new DateTimeZone('UTC'))),
            new RecentTrack('Radiohead', 'OK Computer', new DateTimeImmutable('2026-05-20 11:00:00', new DateTimeZone('UTC'))),
        ]);

        $this->dispatchPoll();

        self::assertSame(2, $this->rowCount());
        self::assertSame(
            ['lastfm_scrobble', 'lastfm_scrobble'],
            $this->connection->fetchFirstColumn('SELECT source FROM music_listening_sessions')
        );
    }

    public function testRepeatedPollIsIdempotent(): void
    {
        $this->installLastFmStub([
            new RecentTrack('Pink Floyd', 'The Wall', new DateTimeImmutable('2026-05-20 10:00:00', new DateTimeZone('UTC'))),
            new RecentTrack('Radiohead', 'OK Computer', new DateTimeImmutable('2026-05-20 11:00:00', new DateTimeZone('UTC'))),
        ]);

        $this->dispatchPoll();
        $this->dispatchPoll();

        self::assertSame(2, $this->rowCount());
    }

    public function testPollWithEmptyHistoryInsertsNothing(): void
    {
        $this->installLastFmStub([]);

        $this->dispatchPoll();

        self::assertSame(0, $this->rowCount());
    }

    /**
     * @param RecentTrack[] $tracks
     */
    private function installLastFmStub(array $tracks): void
    {
        $this->lastFm->method('getRecentTracks')->willReturn($tracks);
    }

    /**
     * Drives the handler directly with a stubbed Last.fm port. The handler's
     * inner LogListeningSession dispatch still flows through the real command
     * bus + repository, so dedup/persistence are exercised end-to-end — we just
     * bypass the async routing that would otherwise queue the poll command.
     */
    private function dispatchPoll(): void
    {
        /** @var MessageBusInterface $commandBus */
        $commandBus = static::getContainer()->get(MessageBusInterface::class);
        $handler = new PollLastFmRecentTracksHandler($this->lastFm, $commandBus);
        $handler(new PollLastFmRecentTracks('testuser'));
    }

    private function rowCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM music_listening_sessions');
    }
}
