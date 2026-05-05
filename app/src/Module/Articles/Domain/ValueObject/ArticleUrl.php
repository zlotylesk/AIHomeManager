<?php

declare(strict_types=1);

namespace App\Module\Articles\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ArticleUrl
{
    private const array ALLOWED_SCHEMES = ['http', 'https'];

    private string $url;

    public function __construct(string $url)
    {
        $trimmed = trim($url);

        if ('' === $trimmed) {
            throw new InvalidArgumentException('Article URL cannot be empty.');
        }

        $scheme = strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        if ('' === $scheme) {
            throw new InvalidArgumentException(sprintf('Invalid URL: "%s".', $url));
        }

        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new InvalidArgumentException(sprintf('Article URL scheme must be http or https, got "%s".', $scheme));
        }

        if (false === filter_var($trimmed, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(sprintf('Invalid URL: "%s".', $url));
        }

        $this->url = $trimmed;
    }

    public function value(): string
    {
        return $this->url;
    }
}
