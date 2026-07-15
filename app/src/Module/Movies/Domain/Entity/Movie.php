<?php

declare(strict_types=1);

namespace App\Module\Movies\Domain\Entity;

use App\Module\Movies\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A single film in the collection. Unlike a series a movie has no season/episode
 * hierarchy, so the aggregate is flat: it owns its identity, its title and when
 * it entered the collection.
 *
 * Catalog metadata, the watched flag and the rating are deliberately absent —
 * they arrive with the tickets that introduce their behavior.
 */
final class Movie
{
    public function __construct(
        private readonly string $id,
        private Title $title,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Movie id cannot be empty.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function title(): Title
    {
        return $this->title;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function rename(Title $title): void
    {
        $this->title = $title;
    }
}
