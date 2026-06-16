<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Service;

use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\Entity\WatchSession;
use DateTimeImmutable;

/**
 * Pure algorithm: deterministically packs a pool of watchlist Videos into
 * ≤ targetSeconds WatchSessions, grouped by channel.
 *
 * Algorithm (see epic HMAI-160):
 *   1. Drop videos already started or watched.
 *   2. Group remaining videos by channel.
 *   3. Sort channels DESC by video count (densest first).
 *   4. Within a channel, sort ASC by duration (shortest first).
 *   5. Greedy-pack into sessions ≤ targetSeconds; overflow video (> target)
 *      gets its own session and closes the current one first.
 *
 * Zero I/O — fully unit-testable in isolation.
 */
final readonly class WatchSessionSplitter
{
    public const int DEFAULT_TARGET_SECONDS = 1800;

    /**
     * @param Video[] $videos
     *
     * @return WatchSession[]
     */
    public function split(
        array $videos,
        int $targetSeconds = self::DEFAULT_TARGET_SECONDS,
        ?DateTimeImmutable $now = null,
    ): array {
        $now ??= new DateTimeImmutable();

        $pool = array_filter($videos, static fn (Video $v): bool => $v->isInSplitPool());
        if ([] === $pool) {
            return [];
        }

        /** @var array<string, Video[]> $byChannel */
        $byChannel = [];
        foreach ($pool as $video) {
            $byChannel[$video->channel()->value()][] = $video;
        }

        uksort(
            $byChannel,
            static fn (string $a, string $b): int => count($byChannel[$b]) <=> count($byChannel[$a]),
        );

        foreach ($byChannel as &$channelVideos) {
            usort(
                $channelVideos,
                static fn (Video $a, Video $b): int => $a->duration()->toSeconds() <=> $b->duration()->toSeconds(),
            );
        }
        unset($channelVideos);

        $sessions = [];
        $currentIds = [];
        $currentDuration = 0;

        foreach ($byChannel as $channelVideos) {
            foreach ($channelVideos as $video) {
                $videoSeconds = $video->duration()->toSeconds();

                if ($videoSeconds > $targetSeconds) {
                    if ([] !== $currentIds) {
                        $sessions[] = WatchSession::create($currentIds, $currentDuration, $now);
                        $currentIds = [];
                        $currentDuration = 0;
                    }
                    $sessions[] = WatchSession::create([$video->id()], $videoSeconds, $now);

                    continue;
                }

                if ($currentDuration + $videoSeconds <= $targetSeconds) {
                    $currentIds[] = $video->id();
                    $currentDuration += $videoSeconds;

                    continue;
                }

                $sessions[] = WatchSession::create($currentIds, $currentDuration, $now);
                $currentIds = [$video->id()];
                $currentDuration = $videoSeconds;
            }
        }

        if ([] !== $currentIds) {
            $sessions[] = WatchSession::create($currentIds, $currentDuration, $now);
        }

        return $sessions;
    }
}
