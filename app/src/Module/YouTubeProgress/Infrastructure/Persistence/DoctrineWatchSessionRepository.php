<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Infrastructure\Persistence;

use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use App\Module\YouTubeProgress\Domain\Repository\WatchSessionRepositoryInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\WatchSessionId;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * DBAL-based repository for WatchSession.
 *
 * We bypass the ORM here on purpose: the aggregate carries an ordered list of
 * YoutubeVideoId VOs that lives in a junction table — Doctrine's UnitOfWork
 * would need either an embedded collection mapping or post-load reflection
 * trickery to reconstruct it, both of which leak persistence concerns into
 * the Domain. DBAL + `WatchSession::reconstitute()` keeps the aggregate
 * persistence-agnostic and the SQL boring.
 *
 * The XML mapping for `watch_sessions` (Domain WatchSession entity) is kept
 * so `doctrine:schema:validate` gates drift on that table. The junction table
 * is excluded via `schema_filter` — Doctrine would otherwise want to drop the
 * FK it doesn't know about, and modelling the FK as a relationship would force
 * a circular entity reference back into the aggregate.
 */
final readonly class DoctrineWatchSessionRepository implements WatchSessionRepositoryInterface
{
    private const string TABLE_SESSIONS = 'watch_sessions';
    private const string TABLE_VIDEOS = 'watch_session_videos';

    public function __construct(private Connection $connection)
    {
    }

    public function save(WatchSession $session): void
    {
        $id = $session->id()->value;
        $row = [
            'total_duration_seconds' => $session->totalDurationSeconds(),
            'created_at' => $session->createdAt()->format('Y-m-d H:i:s'),
            'youtube_playlist_id' => $session->youtubePlaylistId(),
        ];

        $this->connection->transactional(function (Connection $conn) use ($id, $row, $session): void {
            $exists = (bool) $conn->fetchOne(
                'SELECT 1 FROM '.self::TABLE_SESSIONS.' WHERE id = ?',
                [$id],
            );

            if ($exists) {
                $conn->update(self::TABLE_SESSIONS, $row, ['id' => $id]);
                // Wipe the junction so position/order changes on the aggregate
                // are honoured. FK ON DELETE CASCADE doesn't help here (parent
                // row stays), so we delete explicitly.
                $conn->executeStatement(
                    'DELETE FROM '.self::TABLE_VIDEOS.' WHERE watch_session_id = ?',
                    [$id],
                );
            } else {
                $conn->insert(self::TABLE_SESSIONS, ['id' => $id] + $row);
            }

            foreach ($session->videoIds() as $position => $videoId) {
                $conn->insert(self::TABLE_VIDEOS, [
                    'watch_session_id' => $id,
                    'position' => $position,
                    'youtube_video_id' => $videoId->value(),
                ]);
            }
        });
    }

    public function findById(WatchSessionId $id): ?WatchSession
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, total_duration_seconds, created_at, youtube_playlist_id FROM '.self::TABLE_SESSIONS.' WHERE id = ?',
            [$id->value],
        );

        if (false === $row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /** @return WatchSession[] */
    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, total_duration_seconds, created_at, youtube_playlist_id FROM '.self::TABLE_SESSIONS.' ORDER BY created_at DESC, id ASC',
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function deleteAll(): void
    {
        // FK ON DELETE CASCADE on the junction means clearing the parent table
        // is enough — but we wipe both explicitly so the order is deterministic
        // and the intent is unambiguous when reading the SQL log.
        $this->connection->executeStatement('DELETE FROM '.self::TABLE_VIDEOS);
        $this->connection->executeStatement('DELETE FROM '.self::TABLE_SESSIONS);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): WatchSession
    {
        /** @var string $id */
        $id = $row['id'];

        $videoRows = $this->connection->fetchAllAssociative(
            'SELECT youtube_video_id FROM '.self::TABLE_VIDEOS.' WHERE watch_session_id = ? ORDER BY position ASC',
            [$id],
        );

        $videoIds = array_map(
            static fn (array $r): YoutubeVideoId => new YoutubeVideoId((string) $r['youtube_video_id']),
            $videoRows,
        );

        return WatchSession::reconstitute(
            $id,
            $videoIds,
            (int) $row['total_duration_seconds'],
            new DateTimeImmutable((string) $row['created_at']),
            null === $row['youtube_playlist_id'] ? null : (string) $row['youtube_playlist_id'],
        );
    }
}
