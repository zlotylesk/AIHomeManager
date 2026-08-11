<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * A way of reaching the owner.
 *
 * The contract that matters is the one about dependencies: an alert channel may
 * not touch MySQL, Redis or RabbitMQ, because those are the things it exists to
 * report on. A channel that stored a delivery record in the database would be
 * unable to announce the database being down — which is the single failure this
 * whole path is built for.
 *
 * Failure is reported by throwing. The monitor treats an undelivered alert as
 * un-announced and tries again on the next run, so a transient mail outage
 * delays the alert rather than losing it.
 */
interface AlertChannelInterface
{
    public function send(AlertNotice $notice): void;
}
