<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\Entity;

use App\Module\Articles\Domain\ValueObject\ArticleUrl;
use DateTimeImmutable;
use InvalidArgumentException;

final class Article
{
    private const int MAX_TITLE_LENGTH = 500;
    private const int MAX_CATEGORY_LENGTH = 255;

    public function __construct(private readonly string $id, private string $title, private readonly ArticleUrl $url, private ?string $category, private ?int $estimatedReadTime, private readonly DateTimeImmutable $addedAt, private ?DateTimeImmutable $readAt, private bool $isRead)
    {
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

    public function addedAt(): DateTimeImmutable
    {
        return $this->addedAt;
    }

    public function readAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function markAsRead(DateTimeImmutable $at): void
    {
        $this->isRead = true;
        $this->readAt = $at;
    }

    public function updateMetadata(string $title, ?string $category, ?int $estimatedReadTime): void
    {
        $title = trim($title);
        if ('' === $title) {
            throw new InvalidArgumentException(sprintf('Article %s: title cannot be empty.', $this->id));
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new InvalidArgumentException(sprintf('Article %s: title must be at most %d characters.', $this->id, self::MAX_TITLE_LENGTH));
        }

        if (null !== $category) {
            $category = trim($category);

            if ('' === $category) {
                throw new InvalidArgumentException(sprintf('Article %s: category cannot be a blank string — pass null to clear it.', $this->id));
            }
            if (mb_strlen($category) > self::MAX_CATEGORY_LENGTH) {
                throw new InvalidArgumentException(sprintf('Article %s: category must be at most %d characters.', $this->id, self::MAX_CATEGORY_LENGTH));
            }
        }

        if (null !== $estimatedReadTime && $estimatedReadTime < 1) {
            throw new InvalidArgumentException(sprintf('Article %s: estimatedReadTime must be a positive integer, got %d.', $this->id, $estimatedReadTime));
        }

        $this->title = $title;
        $this->category = $category;
        $this->estimatedReadTime = $estimatedReadTime;
    }
}
