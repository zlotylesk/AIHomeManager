<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\ValueObject;

use App\Module\Search\Domain\Enum\SearchResultType;
use InvalidArgumentException;

/**
 * A single normalized search hit, independent of the source module. {@see $type}
 * identifies which module/entity kind it came from, {@see $id} is the source
 * entity's identifier, {@see $url} the link that opens it, {@see $title} the
 * primary label and {@see $snippet} the matched fragment (may be empty).
 */
final readonly class SearchResult
{
    public function __construct(
        public SearchResultType $type,
        public string $id,
        public string $title,
        public string $snippet,
        public string $url,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('Search result id must not be empty.');
        }
        if ('' === trim($title)) {
            throw new InvalidArgumentException('Search result title must not be empty.');
        }
        if ('' === trim($url)) {
            throw new InvalidArgumentException('Search result url must not be empty.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type
            && $this->id === $other->id
            && $this->title === $other->title
            && $this->snippet === $other->snippet
            && $this->url === $other->url;
    }
}
