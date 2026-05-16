<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\QueryHandler;

use App\Module\Tasks\Application\DTO\TaskTimeDTO;
use App\Module\Tasks\Application\DTO\TimeReportDTO;
use App\Module\Tasks\Application\Query\GetTimeReport;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetTimeReportHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(GetTimeReport $query): TimeReportDTO
    {
        $params = [
            'status' => 'completed',
            'from' => $query->dateFrom->format('Y-m-d H:i:s'),
            'to' => $query->dateTo->format('Y-m-d H:i:s'),
        ];

        $sql = 'SELECT id, title, TIMESTAMPDIFF(MINUTE, time_start, time_end) AS minutes
                FROM tasks
                WHERE status = :status AND time_start BETWEEN :from AND :to';

        if (null !== $query->taskTitle) {
            $sql .= ' AND title LIKE :title';
            $params['title'] = '%'.$query->taskTitle.'%';
        }

        $sql .= ' ORDER BY time_start ASC';

        /** @var list<array{id: string, title: string, minutes: int|string|null}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        $totalMinutes = 0;
        /** @var list<TaskTimeDTO> $breakdown */
        $breakdown = [];

        foreach ($rows as $row) {
            $minutes = (int) $row['minutes'];
            $totalMinutes += $minutes;
            $breakdown[] = new TaskTimeDTO(
                taskId: $row['id'],
                title: $row['title'],
                minutes: $minutes,
            );
        }

        return new TimeReportDTO(
            totalMinutes: $totalMinutes,
            totalHours: round($totalMinutes / 60, 2),
            breakdown: $breakdown,
        );
    }
}
