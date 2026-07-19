<?php

declare(strict_types=1);

namespace App\Module\Notifications\Domain\Port;

use App\Shared\Notification\NotificationRequest;
use DateTimeImmutable;

/**
 * Supplies the occurrences that only a periodic review can notice — the ones
 * that follow from the passage of time rather than from something happening
 * ("the deadline is today", "the streak dies at midnight").
 *
 * Implementations answer for a reference moment rather than reading a clock, so
 * "is this worth announcing yet" is testable and the whole sweep sees one
 * consistent instant.
 *
 * Candidates are expressed as the same shared-kernel {@see NotificationRequest}
 * the reactive rail produces, which is what lets both rails share one dedup
 * identity: a subject already announced reactively is not announced again here.
 */
interface NotificationCandidateProviderInterface
{
    /**
     * @return list<NotificationRequest>
     */
    public function candidatesAt(DateTimeImmutable $at): array;
}
