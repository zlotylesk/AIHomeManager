<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\Entity;

use App\Module\Articles\Domain\ValueObject\ArticleUrl;

final class Article
{
    public function __construct(
        private readonly string $id,
        private readonly string $title,
        private readonly ArticleUrl $url,
        private readonly ?string $category,
        private readonly ?int $estimatedReadTime,
        private readonly \DateTimeImmutable $addedAt,
        private readonly ?\DateTimeImmutable $readAt,
        private readonly bool $isRead,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function url(): ArticleUrl
    {
        return $this->url;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function estimatedReadTime(): ?int
    {
        return $this->estimatedReadTime;
    }

    public function addedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function readAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }
}
