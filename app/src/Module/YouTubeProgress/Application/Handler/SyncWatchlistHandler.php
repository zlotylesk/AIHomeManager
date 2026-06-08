<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Handler;

use App\Module\YouTubeProgress\Application\Command\SyncWatchlist;
use App\Module\YouTubeProgress\Domain\Entity\Video;
use App\Module\YouTubeProgress\Domain\Port\YouTubePlaylistReaderInterface;
use App\Module\YouTubeProgress\Domain\Repository\VideoRepositoryInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\ChannelName;
use App\Module\YouTubeProgress\Domain\ValueObject\VideoDuration;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class SyncWatchlistHandler
{
    public function __construct(
        private YouTubePlaylistReaderInterface $reader,
        private VideoRepositoryInterface $videos,
    ) {
    }

    public function __invoke(SyncWatchlist $command): void
    {
        foreach ($this->reader->fetchPlaylistVideos($command->playlistId) as $metadata) {
            $existing = $this->videos->findByYoutubeId(new YoutubeVideoId($metadata->youtubeId));

            if (null !== $existing) {
                // Known video — refresh title/duration (they can change on YouTube)
                // but never touch startedAt/watchedAt. A video the user already
                // started or watched must not be reset into the split pool just
                // because it still sits on the playlist (epic re-sync invariant).
                $existing->updateMetadata($metadata->title, new VideoDuration($metadata->durationSeconds));
                $this->videos->save($existing);

                continue;
            }

            // New video — enters the split pool (startedAt/watchedAt null).
            $this->videos->save(Video::fromYouTube(
                new YoutubeVideoId($metadata->youtubeId),
                $metadata->title,
                new ChannelName($metadata->channel),
                new VideoDuration($metadata->durationSeconds),
                $metadata->publishedAt,
            ));
        }
    }
}
