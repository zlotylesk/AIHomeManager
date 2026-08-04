<?php

declare(strict_types=1);

namespace App\Module\Music\Application\QueryHandler;

use App\Module\Music\Application\DTO\ListeningSessionDTO;
use App\Module\Music\Application\Query\GetListeningHistory;
use App\Shared\Pagination\Page;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetListeningHistoryHandler
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return Page<ListeningSessionDTO>
     */
    public function __invoke(GetListeningHistory $query): Page
    {
        $sql = 'SELECT id, artist, title, played_at, source, play_count FROM music_listening_sessions';

        $conditions = [];
        $params = [];
        $types = [];

        if (null !== $query->from) {
            $conditions[] = 'played_at >= :from';
            $params['from'] = $query->from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        if (null !== $query->to) {
            $conditions[] = 'played_at <= :to';
            $params['to'] = $query->to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }

        if (null !== $query->source) {
            $conditions[] = 'source = :source';
            $params['source'] = $query->source->value;
        }

        $where = [] === $conditions ? '' : ' WHERE '.implode(' AND ', $conditions);
        $sql .= $where;

        $total = (int) $this->connection->executeQuery(
            'SELECT COUNT(*) FROM music_listening_sessions'.$where,
            $params,
            $types,
        )->fetchOne();

        $sql .= ' ORDER BY played_at DESC, id ASC LIMIT :limit OFFSET :offset';
        $params['limit'] = $query->page->perPage;
        $params['offset'] = $query->page->offset();
        $types['limit'] = ParameterType::INTEGER;
        $types['offset'] = ParameterType::INTEGER;

        $rows = $this->connection->executeQuery($sql, $params, $types)->fetchAllAssociative();

        return Page::of(array_map(
            static fn (array $row): ListeningSessionDTO => new ListeningSessionDTO(
                id: (string) $row['id'],
                artist: (string) $row['artist'],
                title: (string) $row['title'],
                playedAt: new DateTimeImmutable((string) $row['played_at'], new DateTimeZone('UTC'))->format(DATE_ATOM),
                source: (string) $row['source'],
                playCount: null !== $row['play_count'] ? (int) $row['play_count'] : null,
            ),
            $rows,
        ), $total, $query->page);
    }
}
