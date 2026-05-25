<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\QueryHandler;

use App\Module\Tasks\Application\DTO\TaskDTO;
use App\Module\Tasks\Application\Query\GetTaskById;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetTaskByIdHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetTaskById $query): ?TaskDTO
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, title, time_start, time_end, TIMESTAMPDIFF(MINUTE, time_start, time_end) AS duration_minutes, status, google_event_id FROM tasks WHERE id = :id',
            ['id' => $query->id]
        );

        if (false === $row) {
            return null;
        }

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
