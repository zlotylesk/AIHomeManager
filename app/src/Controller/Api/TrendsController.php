<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Messaging\QueryBus;
use App\Module\Insights\Application\DTO\TrendsDTO;
use App\Module\Insights\Application\Query\GetTrends;
use App\Module\Insights\Domain\Enum\Granularity;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Thin REST surface for the Insights trends dashboard: one read via query.bus,
 * no domain logic — the window rules live in the {@see GetTrends} query itself,
 * and this only turns its `InvalidArgumentException` into a 422.
 * Version-agnostic path — served under /api/v1/trends and the /api/trends alias
 * (ADR-008, which supersedes the ticket's `src/Controller/TrendsController.php`
 * hint).
 */
#[Route('/trends')]
final class TrendsController extends AbstractController
{
    /** How many buckets back the window reaches when the caller names no range. */
    private const int DEFAULT_BUCKETS = 12;

    public function __construct(
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'Habit trends over time',
        description: 'One time series per habit metric (pages read, episodes watched, YouTube minutes, tracks played, task completion rate) bucketed by week or month. Every bucket in the window carries a point — an inactive bucket is a zero, never a gap. A series with an EMPTY point list means that metric could not be read; the rest of the dashboard is still served.',
        tags: ['Insights'],
        parameters: [
            new OA\Parameter(
                name: 'granularity',
                in: 'query',
                required: false,
                description: 'Bucket size. Defaults to week.',
                schema: new OA\Schema(type: 'string', enum: ['week', 'month'], default: 'week'),
            ),
            new OA\Parameter(
                name: 'from',
                in: 'query',
                required: false,
                description: 'Inclusive window start (Y-m-d). Defaults to 12 buckets back from `to`.',
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
            new OA\Parameter(
                name: 'to',
                in: 'query',
                required: false,
                description: 'Inclusive window end (Y-m-d). Defaults to today.',
                schema: new OA\Schema(type: 'string', format: 'date'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The trends read-model for the requested window.',
                content: new OA\JsonContent(ref: new Model(type: TrendsDTO::class)),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
        ],
    )]
    public function trends(Request $request): JsonResponse
    {
        try {
            $granularity = $this->granularity($request);
            $to = $this->date($request, 'to') ?? new DateTimeImmutable('today');
            $from = $this->date($request, 'from') ?? $this->defaultFrom($granularity, $to);

            $trends = $this->queryBus->ask(new GetTrends($granularity, $from, $to));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (HandlerFailedException $e) {
            if ($e->getPrevious() instanceof InvalidArgumentException) {
                return new JsonResponse(['error' => $e->getPrevious()->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            throw $e;
        }

        return new JsonResponse($this->normalizer->normalize($trends));
    }

    private function granularity(Request $request): Granularity
    {
        $raw = $request->query->get('granularity');
        if (null === $raw || '' === $raw) {
            return Granularity::WEEK;
        }

        return Granularity::tryFrom((string) $raw)
            ?? throw new InvalidArgumentException(sprintf('Unknown granularity "%s". Expected one of: %s.', (string) $raw, implode(', ', array_column(Granularity::cases(), 'value'))));
    }

    private function date(Request $request, string $name): ?DateTimeImmutable
    {
        $raw = $request->query->get($name);
        if (null === $raw || '' === $raw) {
            return null;
        }

        try {
            // Anchored at midnight so a caller passing a bare date and one passing
            // a full timestamp land in the same bucket.
            return new DateTimeImmutable((string) $raw)->setTime(0, 0);
        } catch (Exception) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a valid date, "%s" given.', $name, (string) $raw));
        }
    }

    private function defaultFrom(Granularity $granularity, DateTimeImmutable $to): DateTimeImmutable
    {
        $cursor = $granularity->bucketStart($to);
        for ($i = 1; $i < self::DEFAULT_BUCKETS; ++$i) {
            $cursor = $granularity->previousBucketStart($cursor);
        }

        return $cursor;
    }
}
