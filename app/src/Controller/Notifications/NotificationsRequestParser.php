<?php

declare(strict_types=1);

namespace App\Controller\Notifications;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Shape-checks Notifications payloads so the controller stays a thin
 * decode → parse → dispatch. Domain rules (valid type, valid channel, a
 * well-formed quiet window) stay in the handlers and their value objects — this
 * only rejects payloads that are the wrong *shape*.
 *
 * Throws UnprocessableEntityHttpException, which ApiExceptionListener renders as
 * the same {"error": …} 422 envelope every other module returns.
 */
final class NotificationsRequestParser
{
    /**
     * @return array<string, mixed>
     */
    public function decode(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (!\is_array($decoded)) {
            throw new UnprocessableEntityHttpException('Request body must be a JSON object.');
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function parseBool(array $payload, string $key): bool
    {
        if (!\array_key_exists($key, $payload) || !\is_bool($payload[$key])) {
            throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be a boolean.', $key));
        }

        return $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function parseString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!\is_string($value) || '' === trim($value)) {
            throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be a non-empty string.', $key));
        }

        return trim($value);
    }

    /**
     * An absent or null time is legitimate — it is how the quiet window is
     * cleared — so absence is distinguished from a wrong type.
     *
     * @param array<string, mixed> $payload
     */
    public function parseOptionalTime(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!\is_string($value) || 1 !== preg_match('/^\d{2}:\d{2}$/', $value)) {
            throw new UnprocessableEntityHttpException(sprintf('Field "%s" must be a time in "HH:MM" format or null.', $key));
        }

        return $value;
    }

    public function parseLimit(Request $request, int $default, int $max): int
    {
        $raw = $request->query->get('limit');

        if (null === $raw) {
            return $default;
        }

        if (!is_numeric($raw) || (int) $raw != $raw) {
            throw new UnprocessableEntityHttpException('Query parameter "limit" must be an integer.');
        }

        $limit = (int) $raw;

        if ($limit < 1 || $limit > $max) {
            throw new UnprocessableEntityHttpException(sprintf('Query parameter "limit" must be between 1 and %d.', $max));
        }

        return $limit;
    }
}
