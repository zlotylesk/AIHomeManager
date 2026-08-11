<?php

declare(strict_types=1);

namespace App\Monitoring;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Delivers operational alerts by e-mail, straight through Symfony Mailer.
 *
 * Two deliberate omissions, both in service of the one property that matters
 * here — an alert about a dead dependency must not need that dependency:
 *
 *  - **No Twig.** The notification module renders per-type templates because its
 *    messages are user-facing and varied; an alert is six fields and a runbook
 *    pointer. Building the body in PHP removes the template loader, the
 *    compiled-cache directory and a whole class of render-time failure from the
 *    path that runs when everything else is already broken.
 *  - **No persistence.** Nothing is recorded here. The dispatch engine in the
 *    Notifications module writes a row before sending, which is right for its
 *    retry semantics and fatal for this one: the row lives in the database this
 *    channel has to be able to report on. Deduplication lives in
 *    {@see FileAlertStateStore} instead, on local disk.
 *
 * Mailer's own `SendEmailMessage` is deliberately not routed to the async
 * transport (see messenger.yaml), so `send()` really does reach the transport
 * before returning — which is what makes throwing here meaningful.
 */
final readonly class EmailAlertChannel implements AlertChannelInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private string $from,
        private string $to,
    ) {
    }

    public function send(AlertNotice $notice): void
    {
        $email = new Email()
            ->from($this->from)
            ->to($this->to)
            ->subject($this->subjectFor($notice))
            ->text($this->bodyFor($notice));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $failure) {
            throw new RuntimeException(sprintf('Could not send the "%s" operational alert: %s', $notice->key, $failure->getMessage()), previous: $failure);
        }
    }

    private function subjectFor(AlertNotice $notice): string
    {
        $label = match ($notice->transition) {
            AlertTransition::FIRING => strtoupper($notice->severity->value),
            AlertTransition::ESCALATED => 'ESCALATED to '.strtoupper($notice->severity->value),
            AlertTransition::RESOLVED => 'RESOLVED',
        };

        return sprintf('[AIHM] %s — %s', $label, $notice->title);
    }

    private function bodyFor(AlertNotice $notice): string
    {
        $lines = [$notice->title, ''];

        if (AlertTransition::RESOLVED === $notice->transition) {
            $lines[] = sprintf('Back to normal after %s.', $this->durationBetween($notice));
            $lines[] = '';
        }

        $lines[] = sprintf('Alert:    %s', $notice->key);
        $lines[] = sprintf('Severity: %s', $notice->severity->value);
        $lines[] = sprintf('Since:    %s', $notice->since->format('Y-m-d H:i:s P'));
        $lines[] = sprintf('Observed: %s', $notice->at->format('Y-m-d H:i:s P'));

        if ('' !== trim($notice->detail)) {
            $lines[] = '';
            $lines[] = $notice->detail;
        }

        $lines[] = '';
        $lines[] = 'What to do: docs/operations.md, section "Failure alerting".';

        return implode("\n", $lines)."\n";
    }

    private function durationBetween(AlertNotice $notice): string
    {
        $elapsed = $notice->since->diff($notice->at);
        $days = (int) $elapsed->format('%a');

        if ($days > 0) {
            return sprintf('%dd %dh %dm', $days, $elapsed->h, $elapsed->i);
        }

        if ($elapsed->h > 0) {
            return sprintf('%dh %dm', $elapsed->h, $elapsed->i);
        }

        return sprintf('%dm', $elapsed->i);
    }
}
