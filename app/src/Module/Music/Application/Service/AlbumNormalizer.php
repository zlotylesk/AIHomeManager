<?php

declare(strict_types=1);

namespace App\Module\Music\Application\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Normalizes "{artist} {title}" pairs to a stable comparison key.
 *
 * If a regex fails at runtime (most commonly PREG_BAD_UTF8_ERROR from malformed
 * input coming back from Last.fm/Discogs), we log a warning with the actual
 * preg error and degrade gracefully — returning the partially-processed string
 * so one bad album does not break the whole music comparison feature. Without
 * the log, these failures were silent and showed up only as an unexplained
 * drop in match score.
 *
 * Same graceful-degrade-with-warning policy applies when iconv() rejects the
 * input entirely (returns false). With the //IGNORE flag iconv usually recovers
 * silently, but on completely broken byte sequences it can still fail.
 */
final readonly class AlbumNormalizer
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.music')]
        private LoggerInterface $logger,
    ) {
    }

    public function normalize(string $artist, string $title): string
    {
        $combined = $artist.' '.$title;
        $logContext = ['artist' => $artist, 'title' => $title];

        // Remove parenthetical content: (Remastered 2011), [Deluxe Edition], {Special}
        $combined = $this->applyRegex('/\s*[\(\[\{][^\)\]\}]*[\)\]\}]\s*/u', ' ', $combined, $logContext);

        $combined = mb_strtolower(trim($combined), 'UTF-8');

        // Transliterate diacritics to ASCII (works without intl extension).
        // //IGNORE drops bytes that can't be transliterated — usually recovers
        // from minor UTF-8 issues silently. Total failure (false return) is
        // worth a warning so the comparison-key quality can be audited.
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $combined);
        if (false !== $transliterated) {
            $combined = $transliterated;
        } else {
            $this->logger->warning('AlbumNormalizer: iconv failed, keeping pre-transliteration input', $logContext);
        }

        // Keep only alphanumeric and spaces
        $combined = $this->applyRegex('/[^a-z0-9 ]/u', '', $combined, $logContext);

        // Normalize whitespace
        return $this->applyRegex('/\s+/', ' ', trim($combined), $logContext);
    }

    /**
     * @param array{artist: string, title: string} $logContext
     */
    private function applyRegex(string $pattern, string $replacement, string $subject, array $logContext): string
    {
        $result = preg_replace($pattern, $replacement, $subject);

        if (null === $result) {
            $this->logger->warning('AlbumNormalizer: preg_replace returned null, falling back to unprocessed input', [
                'pattern' => $pattern,
                'preg_error' => preg_last_error_msg(),
                ...$logContext,
            ]);

            return $subject;
        }

        return $result;
    }
}
