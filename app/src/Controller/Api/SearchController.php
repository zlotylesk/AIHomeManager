<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Messaging\QueryBus;
use App\Module\Search\Application\Query\GetSearchFacets;
use App\Module\Search\Application\Query\Search;
use App\Module\Search\Domain\Enum\SearchResultType;
use App\Module\Search\Domain\ReadModel\SearchFacet;
use App\Module\Search\Domain\ValueObject\SearchQuery;
use App\Module\Search\Domain\ValueObject\SearchResult;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/search')]
final class SearchController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'Global cross-module search',
        description: 'Full-text search across every module (books, articles, series, music, tasks), ranked by relevance and paginated. Optionally narrowed to a single result type.',
        tags: ['Search'],
        parameters: [
            new OA\QueryParameter(name: 'q', description: 'The search phrase (required, non-blank).', required: true, schema: new OA\Schema(type: 'string', minLength: 1), example: 'dune'),
            new OA\QueryParameter(name: 'type', description: 'Restrict results to one module/entity kind.', required: false, schema: new OA\Schema(type: 'string', enum: ['article', 'book', 'series', 'music', 'task'])),
            new OA\QueryParameter(name: 'page', description: '1-based page number.', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\QueryParameter(name: 'perPage', description: 'Results per page (1–100).', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The ranked, paginated matches (an empty array when nothing matches).',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: SearchResult::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function search(Request $request): JsonResponse
    {
        $term = $request->query->getString('q');
        $typeParam = $request->query->getString('type');
        $page = $request->query->getInt('page', 1);
        $perPage = $request->query->getInt('perPage', 20);

        try {
            $typeFilter = '' === $typeParam ? null : SearchResultType::tryFrom($typeParam);
            if ('' !== $typeParam && null === $typeFilter) {
                throw new InvalidArgumentException(sprintf('Unknown search type "%s".', $typeParam));
            }
            $criteria = new SearchQuery($term, $typeFilter, $page, $perPage);
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var SearchResult[] $results */
        $results = $this->queryBus->ask(new Search($criteria));

        return new JsonResponse($this->normalizer->normalize($results));
    }

    #[Route('/facets', methods: ['GET'])]
    #[OA\Get(
        summary: 'Per-type match counts for a search phrase',
        description: <<<'TEXT'
            How many documents of each module/entity kind the phrase matches, so a client can
            offer "Books (42)" instead of making the user page blindly.

            Two properties that hold on every backend: the counts span the **whole** match set
            rather than one page, and they deliberately **ignore any type filter** — facets are
            the alternatives on offer, so narrowing them by the current selection would leave
            exactly one. Types with no matches are omitted rather than returned as zero.

            This is a separate resource on purpose: folding the counts into `GET /search` would
            change that endpoint's array response into an envelope, which ADR-008 reserves for
            `/api/v2`.
            TEXT,
        tags: ['Search'],
        parameters: [
            new OA\QueryParameter(name: 'q', description: 'The search phrase (required, non-blank).', required: true, schema: new OA\Schema(type: 'string', minLength: 1), example: 'dune'),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Match counts ordered by count descending, then type (an empty array when nothing matches).',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: SearchFacet::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function facets(Request $request): JsonResponse
    {
        try {
            // The page/perPage defaults are irrelevant here (the engine ignores
            // them for facets) but the VO is what validates the phrase, so the
            // endpoint reuses it rather than growing a second rule for "blank".
            $criteria = new SearchQuery($request->query->getString('q'));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var list<SearchFacet> $facets */
        $facets = $this->queryBus->ask(new GetSearchFacets($criteria));

        return new JsonResponse($this->normalizer->normalize($facets));
    }
}
