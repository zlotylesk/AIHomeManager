<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\Entity;

final class ArticleDailyPick
{
    public function __construct(
        private readonly string $id,
        private readonly string $articleId,
        private readonly \DateTimeImmutable $pickedAt,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function articleId(): string
    {
        return $this->articleId;
    }

    public function pickedAt(): \DateTimeImmutable
    {
        return $this->pickedAt;
    }
}
