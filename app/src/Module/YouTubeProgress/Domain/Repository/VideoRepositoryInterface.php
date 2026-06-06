<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Domain\Repository;

use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;

interface VideoRepositoryInterface
{
    public function save(Video $video): void;

    public function findByYoutubeId(YoutubeVideoId $id): ?Video;

    /** @return Video[] */
    public function findAllInSplitPool(): array;

    /** @return Video[] */
    public function findAll(): array;
}
