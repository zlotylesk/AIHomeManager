<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function reset(): void
    {
        $this->records = [];
    }

    /** @return array{level: string, message: string, context: array<string, mixed>}|null */
    public function findByMessage(string $needle): ?array
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return $record;
            }
        }

        return null;
    }
}
