<?php

declare(strict_types=1);

namespace App\Module\Articles\Application\DTO;

final class ImportResult
{
    public int $imported = 0;
    public int $skipped = 0;
    public int $errors = 0;
}
