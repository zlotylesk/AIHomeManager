<?php

declare(strict_types=1);

namespace App\Module\Series\Domain\Entity;

use App\Module\Series\Domain\ValueObject\Rating;

final class Episode
{
    private ?Rating $rating = null;

    public function __construct(
        private readonly string $id,
        private readonly string $seasonId,
        private readonly string $title,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function seasonId(): string
    {
        return $this->seasonId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function rating(): ?Rating
    {
        return $this->rating;
    }

    public function rate(Rating $rating): void
    {
        $this->rating = $rating;
    }
}