<?php

declare(strict_types=1);

namespace App\Controller;

use App\Shared\Pagination\PageRequest;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Reads the shared `page` / `perPage` query parameters off a request
 * (the SeriesRequestParser precedent, HMAI-239: shape-checking lives in Glue,
 * meaning stays in the Application layer).
 *
 * A missing parameter falls back to the {@see PageRequest} defaults, so a
 * client that never heard of pagination still gets a bounded response — which
 * is the point of the change. A malformed or out-of-range one is a 422 rather
 * than a silent clamp: quietly serving page 1 to someone who asked for page
 * "abc" reports success for a request that was never understood.
 */
final class PaginationRequestParser
{
    public function parse(Request $request): PageRequest
    {
        try {
            return new PageRequest(
                page: $this->intParam($request, 'page', 1),
                perPage: $this->intParam($request, 'perPage', PageRequest::DEFAULT_PER_PAGE),
            );
        } catch (InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
    }

    private function intParam(Request $request, string $name, int $default): int
    {
        $raw = $request->query->get($name);

        if (null === $raw || '' === $raw) {
            return $default;
        }

        if (1 !== preg_match('/^\d+$/', $raw)) {
            throw new UnprocessableEntityHttpException(sprintf('Query parameter "%s" must be a positive integer.', $name));
        }

        return (int) $raw;
    }
}
