<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\QueryHandler;

use App\Module\Movies\Application\DTO\MovieDTO;
use App\Module\Movies\Application\Query\GetMovies;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetMoviesHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return MovieDTO[] */
    public function __invoke(GetMovies $query): array
    {
        $sql = 'SELECT id, title, watched, watched_at, user_rating, cover_url, year, status, description, created_at
                FROM movies';

        $params = [];

        if (null !== $query->watched) {
            $sql .= ' WHERE watched = :watched';
            $params['watched'] = $query->watched ? 1 : 0;
        }

        $sql .= ' ORDER BY title ASC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map($this->toDTO(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toDTO(array $row): MovieDTO
    {
        return new MovieDTO(
            id: (string) $row['id'],
            title: (string) $row['title'],
            watched: (bool) $row['watched'],
            watchedAt: null === $row['watched_at'] ? null : new DateTimeImmutable((string) $row['watched_at'])->format(DateTimeInterface::ATOM),
            rating: null === $row['user_rating'] ? null : (int) $row['user_rating'],
            coverUrl: null === $row['cover_url'] ? null : (string) $row['cover_url'],
            year: null === $row['year'] ? null : (int) $row['year'],
            status: null === $row['status'] ? null : (string) $row['status'],
            description: null === $row['description'] ? null : (string) $row['description'],
            createdAt: new DateTimeImmutable((string) $row['created_at'])->format(DateTimeInterface::ATOM),
        );
    }
}
