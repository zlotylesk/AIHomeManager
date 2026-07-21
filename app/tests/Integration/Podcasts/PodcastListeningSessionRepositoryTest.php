<?php

declare(strict_types=1);

namespace App\Tests\Integration\Podcasts;

use App\Module\Podcasts\Domain\Entity\Podcast;
use App\Module\Podcasts\Domain\Entity\PodcastListeningSession;
use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use App\Module\Podcasts\Domain\ValueObject\Title;
use App\Module\Podcasts\Infrastructure\Persistence\DoctrinePodcastListeningSessionRepository;
use App\Module\Podcasts\Infrastructure\Persistence\DoctrinePodcastRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the session mapping through a real round trip — the ListeningProgress
 * embeddable in particular, whose two halves are only meaningful together and
 * would be easy to hydrate as a silently wrong pair.
 */
final class PodcastListeningSessionRepositoryTest extends KernelTestCase
{
    private DoctrinePodcastListeningSessionRepository $sessions;
    private DoctrinePodcastRepository $podcasts;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->sessions = new DoctrinePodcastListeningSessionRepository($this->em);
        $this->podcasts = new DoctrinePodcastRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE podcast_listening_sessions');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE podcast_episodes');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE podcasts');
    }

    public function testRoundTripsTheProgressPair(): void
    {
        $listenedAt = new DateTimeImmutable('2026-07-21 19:30:00');

        $this->sessions->save(new PodcastListeningSession(
            's-0000001',
            'p-0000001',
            'e-0000001',
            $listenedAt,
            new ListeningProgress(1_234_567, false),
            new DateTimeImmutable('2026-07-21 19:31:00'),
        ));
        $this->em->clear();

        $found = $this->sessions->findByDedupHash(
            PodcastListeningSession::computeDedupHash('p-0000001', 'e-0000001', $listenedAt)
        );

        self::assertNotNull($found);
        self::assertSame('s-0000001', $found->id());
        self::assertSame('e-0000001', $found->episodeId());
        self::assertSame(1_234_567, $found->progress()->resumePositionMs());
        self::assertFalse($found->progress()->fullyPlayed());
        self::assertSame('2026-07-21 19:30:00', $found->listenedAt()->format('Y-m-d H:i:s'));
    }

    /**
     * A finished episode rewound to the start stores position 0 WITH fullyPlayed
     * — the exact pair that would read as "never opened" if either half were
     * dropped in transit.
     */
    public function testAFinishedAndRewoundEpisodeDoesNotHydrateAsUntouched(): void
    {
        $listenedAt = new DateTimeImmutable('2026-07-21 08:00:00');

        $this->sessions->save(new PodcastListeningSession(
            's-0000002',
            'p-0000001',
            'e-0000002',
            $listenedAt,
            ListeningProgress::completed(),
            new DateTimeImmutable(),
        ));
        $this->em->clear();

        $found = $this->sessions->findByDedupHash(
            PodcastListeningSession::computeDedupHash('p-0000001', 'e-0000002', $listenedAt)
        );

        self::assertNotNull($found);
        self::assertTrue($found->progress()->fullyPlayed());
        self::assertSame(0, $found->progress()->resumePositionMs());
        self::assertTrue($found->progress()->isStarted());
    }

    public function testReturnsNullForAnUnknownOccurrence(): void
    {
        self::assertNull($this->sessions->findByDedupHash(str_repeat('0', 64)));
    }

    /**
     * The dedup rule is enforced by the database too, not only by the handler's
     * lookup: a concurrent poll racing the same occurrence must not slip a
     * second row past it.
     */
    public function testTheDatabaseRefusesASecondRowForOneOccurrence(): void
    {
        $listenedAt = new DateTimeImmutable('2026-07-21 12:00:00');

        $this->sessions->save(new PodcastListeningSession(
            's-0000003',
            'p-0000001',
            'e-0000003',
            $listenedAt,
            new ListeningProgress(1000, false),
            new DateTimeImmutable(),
        ));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->sessions->save(new PodcastListeningSession(
            's-0000004',
            'p-0000001',
            'e-0000003',
            $listenedAt->modify('+3 hours'),
            new ListeningProgress(2000, false),
            new DateTimeImmutable(),
        ));
    }

    public function testExternalIdRoundTripsOnTheCatalog(): void
    {
        $podcast = new Podcast('p-0000009', new Title('Radio Nowak'), new DateTimeImmutable());
        $podcast->linkExternal('spotify-show-42');

        $this->podcasts->save($podcast);
        $this->em->clear();

        $found = $this->podcasts->findByExternalId('spotify-show-42');

        self::assertNotNull($found);
        self::assertSame('p-0000009', $found->id());
    }

    public function testALocallyCreatedShowHasNoExternalId(): void
    {
        $this->podcasts->save(new Podcast('p-0000010', new Title('Lokalny'), new DateTimeImmutable()));
        $this->em->clear();

        $found = $this->podcasts->findById('p-0000010');

        self::assertNotNull($found);
        self::assertNull($found->externalId());
    }
}
