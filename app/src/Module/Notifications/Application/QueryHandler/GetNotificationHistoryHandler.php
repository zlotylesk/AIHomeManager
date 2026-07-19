<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\QueryHandler;

use App\Module\Notifications\Application\DTO\NotificationDTO;
use App\Module\Notifications\Application\Query\GetNotificationHistory;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final readonly class GetNotificationHistoryHandler
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<NotificationDTO>
     */
    public function __invoke(GetNotificationHistory $query): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, type, channel, status, payload, created_at, sent_at, failure_reason '
            .'FROM notifications ORDER BY created_at DESC, id DESC LIMIT :limit',
            ['limit' => $query->limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static function (array $row): NotificationDTO {
                /** @var array<string, mixed> $payload */
                $payload = json_decode((string) $row['payload'], true, 512, \JSON_THROW_ON_ERROR);

                return new NotificationDTO(
                    id: (string) $row['id'],
                    type: (string) $row['type'],
                    channel: (string) $row['channel'],
                    status: (string) $row['status'],
                    payload: $payload,
                    createdAt: new DateTimeImmutable((string) $row['created_at'])->format(DateTimeInterface::ATOM),
                    sentAt: null === $row['sent_at']
                        ? null
                        : new DateTimeImmutable((string) $row['sent_at'])->format(DateTimeInterface::ATOM),
                    failureReason: null === $row['failure_reason'] ? null : (string) $row['failure_reason'],
                );
            },
            $rows,
        );
    }
}
