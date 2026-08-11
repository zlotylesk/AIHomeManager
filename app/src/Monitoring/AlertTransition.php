<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Why an alert is being announced *now*.
 *
 * The monitor announces state changes, never state: a failure that is still
 * failing has already been said once and saying it again every five minutes
 * would train the reader to ignore the channel. Only these three moments are
 * worth an e-mail.
 */
enum AlertTransition: string
{
    /** First time this state was seen. */
    case FIRING = 'firing';

    /** Already announced, but it has since got worse. */
    case ESCALATED = 'escalated';

    /** It stopped. Worth saying, because otherwise silence is ambiguous. */
    case RESOLVED = 'resolved';
}
