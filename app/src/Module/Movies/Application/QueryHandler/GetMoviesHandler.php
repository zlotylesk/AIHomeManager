<?php

declare(strict_types=1);

namespace App\Module\Movies\Application\QueryHandler;

use App\Module\Movies\Application\DTO\MovieDTO;
use App\Module\Movies\Application\Query\GetMovies;
use App\Shared\Pagination\Page;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetMoviesHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return Page<MovieDTO> */
    public function __invoke(GetMovies $query): Page
    {
        $where = '';
        $params = [];

        if (null !== $query->watched) {
            $where = ' WHERE watched = :watched';
            $params['watched'] = $query->watched ? 1 : 0;
        }

        // Counted over the whole filtered set rather than the page, so a client
        // can tell there is more without asking for every row.
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM movies'.$where, $params);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, watched, watched_at, user_rating, cover_url, year, status, description, created_at
                FROM movies'.$where.' ORDER BY title ASC, id ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            // MySQL rejects a LIMIT bound as a string, so both bounds are typed.
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of(array_map($this->toDTO(...), $rows), $total, $query->page);
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
