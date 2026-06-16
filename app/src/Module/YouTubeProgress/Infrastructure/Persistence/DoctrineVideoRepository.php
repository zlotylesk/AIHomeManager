<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Infrastructure\Persistence;

use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\Repository\VideoRepositoryInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineVideoRepository implements VideoRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(Video $video): void
    {
        $this->entityManager->persist($video);
        $this->entityManager->flush();
    }

    public function findByYoutubeId(YoutubeVideoId $id): ?Video
    {
        return $this->entityManager->find(Video::class, $id->value());
    }

    /** @return Video[] */
    public function findAllInSplitPool(): array
    {
        return $this->entityManager->createQuery(
            'SELECT v FROM '.Video::class.' v WHERE v.startedAt IS NULL AND v.watchedAt IS NULL'
        )->getResult();
    }

    /** @return Video[] */
    public function findAll(): array
    {
        return $this->entityManager->createQuery('SELECT v FROM '.Video::class.' v')->getResult();
    }
}
