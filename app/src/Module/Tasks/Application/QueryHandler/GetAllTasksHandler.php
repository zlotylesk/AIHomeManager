<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\QueryHandler;

use App\Module\Tasks\Application\DTO\TaskDTO;
use App\Module\Tasks\Application\Query\GetAllTasks;
use App\Shared\Pagination\Page;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllTasksHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return Page<TaskDTO> */
    public function __invoke(GetAllTasks $query): Page
    {
        $where = '';
        $params = [];

        if (null !== $query->status) {
            $where = ' WHERE status = :status';
            $params['status'] = $query->status;
        }

        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tasks'.$where, $params);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, time_start, time_end, TIMESTAMPDIFF(MINUTE, time_start, time_end) AS duration_minutes, status, google_event_id
                FROM tasks'.$where.' ORDER BY time_start DESC, id ASC LIMIT :limit OFFSET :offset',
            $params + ['limit' => $query->page->perPage, 'offset' => $query->page->offset()],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return Page::of(array_map($this->toDTO(...), $rows), $total, $query->page);
    }

    /** @param array<string, mixed> $row */
    private function toDTO(array $row): TaskDTO
    {
        return new TaskDTO(
            id: $row['id'],
            title: $row['title'],
            start: new DateTimeImmutable($row['time_start'])->format(DateTimeInterface::ATOM),
            end: new DateTimeImmutable($row['time_end'])->format(DateTimeInterface::ATOM),
            durationMinutes: (int) $row['duration_minutes'],
            status: $row['status'],
            googleEventId: $row['google_event_id'],
        );
    }
}
