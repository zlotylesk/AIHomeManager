<?php

declare(strict_types=1);

namespace App\Module\Tasks\Domain\ValueObject;

use InvalidArgumentException;

final readonly class TaskTitle
{
    public function __construct(private string $value)
    {
        if ('' === trim($value)) {
            throw new InvalidArgumentException('Task title cannot be empty.');
        }

        if (mb_strlen($value) > 255) {
            throw new InvalidArgumentException(sprintf('Task title cannot exceed 255 characters, %d given.', mb_strlen($value)));
        }
    }

    public function value(): string
    {
        return $this->value;
    }
}
