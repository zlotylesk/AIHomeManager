<?php

declare(strict_types=1);

namespace App\Tests\Integration\Music;

use App\Module\Music\Application\Query\GetListeningHistory;
use App\Module\Music\Application\QueryHandler\GetListeningHistoryHandler;
use App\Module\Music\Domain\Entity\ListeningSession;
use App\Module\Music\Domain\Enum\ListeningSource;
use App\Module\Music\Domain\ValueObject\AlbumArtist;
use App\Module\Music\Domain\ValueObject\AlbumTitle;
use App\Module\Music\Infrastructure\Persistence\DoctrineListeningSessionRepository;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class ListeningSessionRepositoryTest extends KernelTestCase
{
    private DoctrineListeningSessionRepository $repository;
    private GetListeningHistoryHandler $historyHandler;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = new DoctrineListeningSessionRepository($this->em);
        $this->historyHandler = new GetListeningHistoryHandler($this->em->getConnection());

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE music_listening_sessions');
    }

    public function testSaveAndExistsByDedupHash(): void
    {
        $session = $this->makeSession('Pink Floyd', 'The Wall', '2026-05-20 10:00:00', ListeningSource::LASTFM_SCROBBLE);
        $this->repository->save($session);

        self::assertTrue($this->repository->existsByDedupHash($session->dedupHash()));
    }

    public function testExistsByDedupHashReturnsFalseForUnknownHash(): void
    {
        self::assertFalse($this->repository->existsByDedupHash(hash('sha256', 'never-seen')));
    }

    public function testHistoryReturnsSessionsOrderedByPlayedAtDesc(): void
    {
        $this->repository->save($this->makeSession('A', 'Older', '2026-05-18 09:00:00', ListeningSource::MANUAL));
        $this->repository->save($this->makeSession('B', 'Newer', '2026-05-20 09:00:00', ListeningSource::LASTFM_SCROBBLE));

        $result = ($this->historyHandler)(new GetListeningHistory());

        self::assertCount(2, $result);
        self::assertSame('Newer', $result[0]->title);
        self::assertSame('Older', $result[1]->title);
        self::assertSame('2026-05-20T09:00:00+00:00', $result[0]->playedAt);
    }

    public function testHistoryFiltersBySource(): void
    {
        $this->repository->save($this->makeSession('A', 'Scrobbled', '2026-05-20 09:00:00', ListeningSource::LASTFM_SCROBBLE));
        $this->repository->save($this->makeSession('B', 'Manual', '2026-05-20 10:00:00', ListeningSource::MANUAL));

        $result = ($this->historyHandler)(new GetListeningHistory(source: ListeningSource::MANUAL));

        self::assertCount(1, $result);
        self::assertSame('Manual', $result[0]->title);
        self::assertSame('manual', $result[0]->source);
    }

    public function testHistoryFiltersByDateRange(): void
    {
        $this->repository->save($this->makeSession('A', 'Before', '2026-05-10 09:00:00', ListeningSource::MANUAL));
        $this->repository->save($this->makeSession('B', 'Inside', '2026-05-20 09:00:00', ListeningSource::MANUAL));
        $this->repository->save($this->makeSession('C', 'After', '2026-05-30 09:00:00', ListeningSource::MANUAL));

        $result = ($this->historyHandler)(new GetListeningHistory(
            from: new DateTimeImmutable('2026-05-15 00:00:00', new DateTimeZone('UTC')),
            to: new DateTimeImmutable('2026-05-25 00:00:00', new DateTimeZone('UTC')),
        ));

        self::assertCount(1, $result);
        self::assertSame('Inside', $result[0]->title);
    }

    public function testHistoryRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->repository->save($this->makeSession('Artist', 'Album '.$i, sprintf('2026-05-2%d 09:00:00', $i), ListeningSource::MANUAL));
        }

        $result = ($this->historyHandler)(new GetListeningHistory(limit: 3));

        self::assertCount(3, $result);
    }

    private function makeSession(string $artist, string $title, string $playedAt, ListeningSource $source): ListeningSession
    {
        return new ListeningSession(
            id: Uuid::v4()->toRfc4122(),
            artist: new AlbumArtist($artist),
            title: new AlbumTitle($title),
            playedAt: new DateTimeImmutable($playedAt, new DateTimeZone('UTC')),
            source: $source,
        );
    }
}
