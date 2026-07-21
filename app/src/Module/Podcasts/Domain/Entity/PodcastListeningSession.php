<?php

declare(strict_types=1);

namespace App\Module\Podcasts\Domain\Entity;

use App\Module\Podcasts\Domain\ValueObject\ListeningProgress;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Local, user-owned record of listening to one episode on one day. The Music
 * ListeningSession plays the same role for albums, but the dedup rule here has
 * to be different — see below.
 *
 * Music can hash the play timestamp at second resolution because a scrobble IS
 * an event with a real moment. Spotify reports no listen moment for podcast
 * episodes (see the module notes in CLAUDE.md), so `listenedAt` is usually the
 * time the poll observed the progress — a fresh value on every single poll.
 * Hashing that would make each poll insert a new row and dedup would do nothing
 * at all. The identity is therefore podcast + episode + the DAY of the listen:
 * re-polling on the same day collapses onto one record, while listening again
 * tomorrow is genuinely a new session.
 *
 * Not `readonly` as a whole: the day's record absorbs later observations of the
 * same episode through observeProgress(), so an evening poll finishing an
 * episode is not lost just because the morning poll saw it at five minutes.
 */
final class PodcastListeningSession
{
    private readonly string $dedupHash;

    public function __construct(
        private readonly string $id,
        private readonly string $podcastId,
        private readonly string $episodeId,
        private readonly DateTimeImmutable $listenedAt,
        private ListeningProgress $progress,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Listening session id cannot be empty.');
        }

        if ('' === trim($podcastId)) {
            throw new InvalidArgumentException('Listening session must belong to a podcast.');
        }

        if ('' === trim($episodeId)) {
            throw new InvalidArgumentException('Listening session must belong to an episode.');
        }

        $this->dedupHash = self::computeDedupHash($podcastId, $episodeId, $listenedAt);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function podcastId(): string
    {
        return $this->podcastId;
    }

    public function episodeId(): string
    {
        return $this->episodeId;
    }

    public function listenedAt(): DateTimeImmutable
    {
        return $this->listenedAt;
    }

    public function progress(): ListeningProgress
    {
        return $this->progress;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function dedupHash(): string
    {
        return $this->dedupHash;
    }

    /**
     * Fold a later observation of the same day's listening into this record,
     * reporting whether anything actually moved so the caller can skip a
     * pointless write.
     *
     * Forward-only on purpose. The source reports a resume POSITION, and a
     * listener who finishes an episode and restarts it drops that position back
     * to near zero — taking the lower number at face value would rewrite the
     * day's record to look like they had barely listened. Reaching the end is
     * likewise never un-done: once fully played, it stays played.
     */
    public function observeProgress(ListeningProgress $observed): bool
    {
        $advancedPosition = $observed->resumePositionMs() > $this->progress->resumePositionMs();
        $newlyFinished = $observed->fullyPlayed() && !$this->progress->fullyPlayed();

        if (!$advancedPosition && !$newlyFinished) {
            return false;
        }

        $this->progress = new ListeningProgress(
            max($this->progress->resumePositionMs(), $observed->resumePositionMs()),
            $this->progress->fullyPlayed() || $observed->fullyPlayed(),
        );

        return true;
    }

    /**
     * The day is taken in UTC so that the same instant always lands in the same
     * bucket regardless of the timezone the caller happened to carry.
     */
    public static function computeDedupHash(
        string $podcastId,
        string $episodeId,
        DateTimeImmutable $listenedAt,
    ): string {
        return hash('sha256', implode('|', [
            $podcastId,
            $episodeId,
            $listenedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
        ]));
    }
}
