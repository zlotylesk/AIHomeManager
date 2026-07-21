<?php

declare(strict_types=1);

namespace App\Tests\Integration\Podcasts;

use App\Module\Podcasts\Domain\Entity\Episode;
use App\Module\Podcasts\Domain\Entity\Podcast;
use App\Module\Podcasts\Domain\ValueObject\Title;
use App\Module\Podcasts\Infrastructure\Persistence\DoctrineEpisodeRepository;
use App\Module\Podcasts\Infrastructure\Persistence\DoctrinePodcastRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Exercises the mapping rather than merely validating it: an embeddable that
 * hydrates wrong only blows up at read time (the hazard that forced the Series
 * series_rating custom DBAL type), so every field goes through a real
 * save → clear → find round trip.
 */
final class PodcastRepositoryTest extends KernelTestCase
{
    private DoctrinePodcastRepository $podcasts;
    private DoctrineEpisodeRepository $episodes;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->podcasts = new DoctrinePodcastRepository($this->em);
        $this->episodes = new DoctrineEpisodeRepository($this->em);

        $this->em->getConnection()->executeStatement('TRUNCATE TABLE podcast_episodes');
        $this->em->getConnection()->executeStatement('TRUNCATE TABLE podcasts');
    }

    public function testPodcastRoundTripsEmbeddedTitleAndMetadata(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-20 21:00:00');
        $podcast = new Podcast('p-0000001', new Title('Radio Naukowe'), $createdAt);
        $podcast->updateMetadata('Karolina Głowacka', 'https://example.test/cover.jpg', 'Nauka po ludzku.');

        $this->podcasts->save($podcast);
        $this->em->clear();

        $found = $this->podcasts->findById('p-0000001');

        self::assertNotNull($found);
        self::assertSame('Radio Naukowe', $found->title()->value());
        self::assertSame('Karolina Głowacka', $found->publisher());
        self::assertSame('https://example.test/cover.jpg', $found->coverUrl());
        self::assertSame('Nauka po ludzku.', $found->description());
        self::assertSame($createdAt->format('Y-m-d H:i:s'), $found->createdAt()->format('Y-m-d H:i:s'));
    }

    public function testPodcastWithoutMetadataHydratesRealNulls(): void
    {
        $this->podcasts->save(new Podcast('p-0000002', new Title('Bez metadanych'), new DateTimeImmutable()));
        $this->em->clear();

        $found = $this->podcasts->findById('p-0000002');

        self::assertNotNull($found);
        self::assertNull($found->publisher());
        self::assertNull($found->coverUrl());
        self::assertNull($found->description());
    }

    public function testPodcastTitleAtMaxLengthSurvivesRoundTrip(): void
    {
        $long = str_repeat('ł', 500);
        $this->podcasts->save(new Podcast('p-0000003', new Title($long), new DateTimeImmutable()));
        $this->em->clear();

        $found = $this->podcasts->findById('p-0000003');

        self::assertNotNull($found);
        self::assertSame(500, mb_strlen($found->title()->value()));
    }

    public function testFindByIdReturnsNullForUnknownId(): void
    {
        self::assertNull($this->podcasts->findById('nope'));
    }

    public function testFindAllOrdersByTitle(): void
    {
        $this->podcasts->save(new Podcast('p-b', new Title('Zzz'), new DateTimeImmutable()));
        $this->podcasts->save(new Podcast('p-a', new Title('Aaa'), new DateTimeImmutable()));
        $this->em->clear();

        $titles = array_map(
            static fn (Podcast $p): string => $p->title()->value(),
            $this->podcasts->findAll(),
        );

        self::assertSame(['Aaa', 'Zzz'], $titles);
    }

    public function testRemoveDeletesPodcast(): void
    {
        $podcast = new Podcast('p-0000004', new Title('Do usunięcia'), new DateTimeImmutable());
        $this->podcasts->save($podcast);

        $this->podcasts->remove($podcast);
        $this->em->clear();

        self::assertNull($this->podcasts->findById('p-0000004'));
    }

    public function testEpisodeRoundTripsMetadataAndForeignKey(): void
    {
        $this->podcasts->save(new Podcast('p-0000005', new Title('Radio Naukowe'), new DateTimeImmutable()));
        $publishedAt = new DateTimeImmutable('2026-07-01 06:00:00');
        $episode = new Episode('e-0000001', 'p-0000005', new Title('Odcinek 100'), new DateTimeImmutable());
        $episode->updateMetadata($publishedAt, 3_600_000);

        $this->episodes->save($episode);
        $this->em->clear();

        $found = $this->episodes->findById('e-0000001');

        self::assertNotNull($found);
        self::assertSame('p-0000005', $found->podcastId());
        self::assertSame('Odcinek 100', $found->title()->value());
        self::assertSame($publishedAt->format('Y-m-d H:i:s'), $found->publishedAt()?->format('Y-m-d H:i:s'));
        self::assertSame(3_600_000, $found->durationMs());
    }

    public function testEpisodeWithoutMetadataHydratesRealNulls(): void
    {
        $this->podcasts->save(new Podcast('p-0000005', new Title('Radio Naukowe'), new DateTimeImmutable()));
        $this->episodes->save(new Episode('e-0000002', 'p-0000005', new Title('Świeży'), new DateTimeImmutable()));
        $this->em->clear();

        $found = $this->episodes->findById('e-0000002');

        self::assertNotNull($found);
        self::assertNull($found->publishedAt());
        self::assertNull($found->durationMs());
    }

    public function testFindByPodcastIdReturnsNewestFirstAndScopesToTheShow(): void
    {
        $this->podcasts->save(new Podcast('p-0000006', new Title('Ten show'), new DateTimeImmutable()));
        $this->podcasts->save(new Podcast('p-0000007', new Title('Inny show'), new DateTimeImmutable()));
        $older = new Episode('e-old', 'p-0000006', new Title('Starszy'), new DateTimeImmutable());
        $older->updateMetadata(new DateTimeImmutable('2026-06-01 06:00:00'), null);
        $newer = new Episode('e-new', 'p-0000006', new Title('Nowszy'), new DateTimeImmutable());
        $newer->updateMetadata(new DateTimeImmutable('2026-07-01 06:00:00'), null);
        $otherShow = new Episode('e-other', 'p-0000007', new Title('Obcy'), new DateTimeImmutable());
        $otherShow->updateMetadata(new DateTimeImmutable('2026-07-15 06:00:00'), null);

        $this->episodes->save($older);
        $this->episodes->save($newer);
        $this->episodes->save($otherShow);
        $this->em->clear();

        $ids = array_map(
            static fn (Episode $e): string => $e->id(),
            $this->episodes->findByPodcastId('p-0000006'),
        );

        self::assertSame(['e-new', 'e-old'], $ids);
    }

    public function testRemoveDeletesEpisode(): void
    {
        $this->podcasts->save(new Podcast('p-0000005', new Title('Radio Naukowe'), new DateTimeImmutable()));
        $episode = new Episode('e-0000003', 'p-0000005', new Title('Do usunięcia'), new DateTimeImmutable());
        $this->episodes->save($episode);

        $this->episodes->remove($episode);
        $this->em->clear();

        self::assertNull($this->episodes->findById('e-0000003'));
    }
}
