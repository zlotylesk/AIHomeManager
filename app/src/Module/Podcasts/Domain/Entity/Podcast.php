<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\Entity;

use App\Module\Podcasts\Domain\ValueObject\Title;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A podcast show the user follows. Episodes are a separate aggregate joined by a
 * plain string FK rather than an ORM association (ADR-007, the Series
 * precedent): reads go through DBAL anyway, so an association would only serve
 * the write path while costing the nullable-VO hydration hazard.
 *
 * Catalog metadata is stored as already-validated primitives — the cover URL
 * goes through the shared CoverUrl VO at the Application boundary, the same way
 * Movies and Series handle theirs.
 */
final class Podcast
{
    private ?string $publisher = null;

    private ?string $coverUrl = null;

    private ?string $description = null;

    public function __construct(
        private readonly string $id,
        private Title $title,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Podcast id cannot be empty.');
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

    public function publisher(): ?string
    {
        return $this->publisher;
    }

    public function coverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function rename(Title $title): void
    {
        $this->title = $title;
    }

    /**
     * Full replace — a null clears the field. The catalog is re-read from the
     * source on every poll, so a field the source dropped must disappear here
     * too rather than linger (the Series updateMetadata precedent).
     */
    public function updateMetadata(?string $publisher, ?string $coverUrl, ?string $description): void
    {
        $this->publisher = $publisher;
        $this->coverUrl = $coverUrl;
        $this->description = $description;
    }
}
