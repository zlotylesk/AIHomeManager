<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\QueryHandler;

use App\Module\Tasks\Application\DTO\TaskDTO;
use App\Module\Tasks\Application\Query\GetAllTasks;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetAllTasksHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return TaskDTO[] */
    public function __invoke(GetAllTasks $query): array
    {
        $sql = 'SELECT id, title, time_start, time_end, TIMESTAMPDIFF(MINUTE, time_start, time_end) AS duration_minutes, status, google_event_id FROM tasks';

        $params = [];

        if (null !== $query->status) {
            $sql .= ' WHERE status = :status';
            $params['status'] = $query->status;
        }

        $sql .= ' ORDER BY time_start DESC';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map($this->toDTO(...), $rows);
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
