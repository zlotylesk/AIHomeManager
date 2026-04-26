<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\Entity;

use App\Module\Articles\Domain\ValueObject\ArticleUrl;

final class Article
{
    private string $title;
    private ?string $category;
    private ?int $estimatedReadTime;
    private bool $isRead;
    private ?\DateTimeImmutable $readAt;

    public function __construct(
        private readonly string $id,
        string $title,
        private readonly ArticleUrl $url,
        ?string $category,
        ?int $estimatedReadTime,
        private readonly \DateTimeImmutable $addedAt,
        ?\DateTimeImmutable $readAt,
        bool $isRead,
    ) {
        $this->title = $title;
        $this->category = $category;
        $this->estimatedReadTime = $estimatedReadTime;
        $this->readAt = $readAt;
        $this->isRead = $isRead;
    }

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

    public function markAsRead(\DateTimeImmutable $at): void
    {
        $this->isRead = true;
        $this->readAt = $at;
    }

    public function updateMetadata(string $title, ?string $category, ?int $estimatedReadTime): void
    {
        $this->title = $title;
        $this->category = $category;
        $this->estimatedReadTime = $estimatedReadTime;
    }
}
