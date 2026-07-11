<?php

declare(strict_types=1);

namespace App\Module\Search\Domain\Enum;

/**
 * The normalized kind of a global-search hit. Each case identifies both the
 * source module and the entity kind it indexes (BOOK ← Books, SERIES ← Series,
 * …), so a {@see \App\Module\Search\Domain\ValueObject\SearchResult} stays
 * independent of any source module's own types.
 */
enum SearchResultType: string
{
    case ARTICLE = 'article';
    case BOOK = 'book';
    case SERIES = 'series';
    case MUSIC = 'music';
    case TASK = 'task';
}
