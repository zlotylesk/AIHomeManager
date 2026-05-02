<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\Entity;

use DateTimeImmutable;

final readonly class ArticleDailyPick
{
    public function __construct(
        private string $id,
        private string $articleId,
        private DateTimeImmutable $pickedAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function articleId(): string
    {
        return $this->articleId;
    }

    public function pickedAt(): DateTimeImmutable
    {
        return $this->pickedAt;
    }
}
