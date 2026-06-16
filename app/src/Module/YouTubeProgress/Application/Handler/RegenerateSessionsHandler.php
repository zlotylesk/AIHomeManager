<?php

declare(strict_types=1);

namespace App\Module\YouTubeProgress\Application\Handler;

use App\Module\YouTubeProgress\Application\Command\RegenerateSessions;
use App\Module\YouTubeProgress\Application\Service\WatchSessionSplitter;
use App\Module\YouTubeProgress\Domain\Repository\VideoRepositoryInterface;
use App\Module\YouTubeProgress\Domain\Repository\WatchSessionRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RegenerateSessionsHandler
{
    public function __construct(
        private VideoRepositoryInterface $videos,
        private WatchSessionRepositoryInterface $sessions,
        private WatchSessionSplitter $splitter,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RegenerateSessions $command): void
    {
        $this->em->beginTransaction();

        try {
            $this->sessions->deleteAll();

            $pool = $this->videos->findAllInSplitPool();
            if ([] === $pool) {
                $this->em->commit();
                $this->logger->info('RegenerateSessions: empty split pool, no sessions created');

                return;
            }

            $newSessions = $this->splitter->split($pool);
            foreach ($newSessions as $session) {
                $this->sessions->save($session);
            }

            $this->em->commit();
            $this->logger->info('RegenerateSessions completed', [
                'session_count' => count($newSessions),
                'video_count' => count($pool),
            ]);
        } catch (Throwable $e) {
            $this->em->rollback();

            throw $e;
        }
    }
}
