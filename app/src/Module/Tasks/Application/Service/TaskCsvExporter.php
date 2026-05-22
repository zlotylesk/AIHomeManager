<?php

declare(strict_types=1);

namespace App\Module\Tasks\Application\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Generator;

final readonly class TaskCsvExporter
{
    /** @var list<string> */
    public const array HEADERS = ['title', 'startTime', 'endTime', 'durationMinutes', 'googleEventId'];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Streams completed tasks within the optional [from, to] window via DBAL
     * cursor. Date filter uses time_start (when the work happened, not when the
     * row was created), matching what a user would expect from a "tasks I did
     * between A and B" export. Matches HMAI-36 dev notes.
     *
     * @return Generator<int, list<scalar|null>>
     */
    public function rows(?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): Generator
    {
        $sql = 'SELECT title, time_start, time_end,
                       TIMESTAMPDIFF(MINUTE, time_start, time_end) AS minutes,
                       google_event_id
                FROM tasks
                WHERE status = :status';
        $params = ['status' => 'completed'];

        if (null !== $from) {
            $sql .= ' AND time_start >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s');
        }
        if (null !== $to) {
            $sql .= ' AND time_start <= :to';
            $params['to'] = $to->format('Y-m-d H:i:s');
        }

        $sql .= ' ORDER BY time_start ASC';

        $result = $this->connection->executeQuery($sql, $params);
        while (false !== ($row = $result->fetchAssociative())) {
            yield [
                $row['title'],
                $row['time_start'],
                $row['time_end'],
                (int) $row['minutes'],
                $row['google_event_id'],
            ];
        }
    }
}
