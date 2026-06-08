<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Handler;

use App\Module\YouTubeProgress\Application\Command\MarkVideoWatched;
use App\Module\YouTubeProgress\Domain\Repository\VideoRepositoryInterface;
use App\Module\YouTubeProgress\Domain\ValueObject\YoutubeVideoId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class MarkVideoWatchedHandler
{
    public function __construct(
        private VideoRepositoryInterface $videos,
    ) {
    }

    public function __invoke(MarkVideoWatched $command): void
    {
        $video = $this->videos->findByYoutubeId(new YoutubeVideoId($command->youtubeVideoId));
        if (null === $video) {
            throw new NotFoundHttpException(sprintf('Video "%s" not found in watchlist', $command->youtubeVideoId));
        }

        // Idempotency and the started→watched ordering invariants live in the
        // aggregate: a prior startedAt survives, and a re-mark is a no-op.
        $video->markWatched($command->at);
        $this->videos->save($video);
    }
}
