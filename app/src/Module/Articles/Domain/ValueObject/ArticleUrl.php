<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ArticleUrl
{
    public function __construct(private string $url)
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(sprintf('Invalid URL: "%s".', $url));
        }
    }

    public function value(): string
    {
        return $this->url;
    }
}
