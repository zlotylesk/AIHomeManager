<?php

declare(strict_types=1);

namespace App\Module\Notifications\Application\CommandHandler;

use App\Module\Notifications\Application\Command\DispatchNotification;
use App\Module\Notifications\Application\Command\ReviewNotificationCandidates;
use App\Module\Notifications\Domain\Port\NotificationCandidateProviderInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * The scheduler rail: hands every candidate the periodic review turned up to the
 * dispatch engine, which then applies preferences, quiet hours and dedup exactly
 * as it does for the reactive rail.
 *
 * Nothing is deduplicated here. The engine already recognizes an occurrence by
 * its subject+window, so a task announced reactively this morning is not
 * announced again by tonight's sweep — one rule, one place, and the two rails
 * cannot drift apart.
 *
 * A provider that throws is logged and skipped rather than aborting the sweep:
 * one unreachable source must not cost the user every other reminder
 * (the Dashboard per-widget fault isolation precedent).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ReviewNotificationCandidatesHandler
{
    public function __construct(
        private NotificationCandidateProviderInterface $candidates,
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ReviewNotificationCandidates $command): void
    {
        $at = new DateTimeImmutable();

        try {
            $candidates = $this->candidates->candidatesAt($at);
        } catch (Throwable $failure) {
            $this->logger->warning('Notification candidate review failed.', ['exception' => $failure]);

            return;
        }

        foreach ($candidates as $candidate) {
            $this->commandBus->dispatch(new DispatchNotification(
                $candidate->type,
                $candidate->subject,
                $candidate->window,
                $candidate->payload,
            ));
        }
    }
}
