<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Recipes\RecipesRequestParser;
use App\Csv\CsvBuilder;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Recipes\Application\Command\MoveMeal;
use App\Module\Recipes\Application\Command\PlanMeal;
use App\Module\Recipes\Application\Command\UnplanMeal;
use App\Module\Recipes\Application\DTO\MealPlanDTO;
use App\Module\Recipes\Application\DTO\ShoppingListDTO;
use App\Module\Recipes\Application\Exception\MealAlreadyPlannedException;
use App\Module\Recipes\Application\Exception\PlannedMealNotFoundException;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Application\Query\GetMealPlan;
use App\Module\Recipes\Application\Query\GetShoppingList;
use App\Module\Recipes\Application\Service\ShoppingListExporter;
use App\Pdf\PdfBuilder;
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
 * Thin REST surface for the meal-planning calendar and the shopping list it
 * generates. Reads via query.bus, writes via command.bus, no domain logic.
 * Version-agnostic path — served under /api/v1/meal-plan and the
 * /api/meal-plan alias (ADR-008).
 *
 * Kept separate from RecipesController rather than nested under
 * `/recipes/meal-plan`: the plan is about days, not about one recipe, and a
 * path implying otherwise would make the shopping list — which spans every
 * recipe in a window — read as if it belonged to a single one.
 */
#[Route('/meal-plan')]
final class MealPlanController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly NormalizerInterface $normalizer,
        private readonly RecipesRequestParser $parser,
    ) {
    }

    #[Route('', methods: ['GET'])]
    #[OA\Get(
        summary: 'The meal plan for a window',
        description: 'Gap-filled in both dimensions: every day of `[from, to]` is present, and every day carries all four slots in their order through the day, empty ones included — so a calendar renders a grid straight from the payload without knowing the slot vocabulary or its sequence. The window is echoed back, which is what distinguishes an empty plan from a request interpreted differently than it meant. Both ends are inclusive and capped at 92 days.',
        tags: ['Recipes'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, description: 'First day of the window, `YYYY-MM-DD`. An impossible day (e.g. `2026-02-31`) is rejected, never rolled forward.', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-08-03')),
            new OA\Parameter(name: 'to', in: 'query', required: true, description: 'Last day of the window, inclusive.', schema: new OA\Schema(type: 'string', format: 'date', example: '2026-08-09')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The plan, day by day and slot by slot.', content: new OA\JsonContent(ref: new Model(type: MealPlanDTO::class))),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function plan(Request $request): JsonResponse
    {
        [$from, $to] = $this->parser->requireWindow($request);

        try {
            $plan = $this->queryBus->ask(new GetMealPlan($from, $to));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->normalizer->normalize($plan));
    }

    #[Route('/shopping-list', methods: ['GET'])]
    #[OA\Get(
        summary: 'The shopping list for a window',
        description: "Every ingredient of every meal planned in `[from, to]`, scaled by each meal's own servings against its recipe's, then summed. Lines are grouped by (name, unit) case-insensitively, so the same ingredient spelled two ways is one line — but two *units* of one ingredient stay two lines, because converting between them would put an invented number on a shopping list. Quantities are raw sums, deliberately unrounded: the precision a unit deserves is a presentation decision, so a client must format rather than print. Unlike the calendar this read is not gap-filled — an empty list means an empty window.",
        tags: ['Recipes'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-08-03')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-08-09')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'What has to be bought for the window.', content: new OA\JsonContent(ref: new Model(type: ShoppingListDTO::class))),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function shoppingList(Request $request): JsonResponse
    {
        [$from, $to] = $this->parser->requireWindow($request);

        try {
            $list = $this->queryBus->ask(new GetShoppingList($from, $to));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->normalizer->normalize($list));
    }

    /**
     * CSV/PDF export of the shopping list (the Tasks/Articles export pattern).
     *
     * The list is dispatched on query.bus rather than re-queried in the
     * exporter, so what is taken to the shop cannot drift from what
     * `/meal-plan/shopping-list` serves and the page shows — for a shopping
     * list that agreement is the entire point.
     */
    #[Route('/shopping-list/export', methods: ['GET'])]
    #[OA\Get(
        summary: 'Export the shopping list as CSV/PDF',
        description: 'The same list `GET /meal-plan/shopping-list` returns, rendered as a file. Quantities are rounded here — the JSON ships raw sums because precision is per unit, but a document read by a person cannot print `0.30000000000000004`; grams and millilitres come out whole, kilograms and litres to three decimals, and units you cannot buy a fraction of (`piece`, `pinch`) round **up**, because a shopping list that leaves the cook an egg short is the one direction of error worth avoiding. The CSV carries the canonical unit identifier (it is sorted and grouped in a spreadsheet, where a stable key beats a caption); the PDF, which is read rather than processed, spells the unit out. The filename carries the window, so exporting three weeks gives three distinguishable files.',
        tags: ['Recipes'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-08-03')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-08-09')),
            new OA\Parameter(name: 'format', in: 'query', required: false, description: 'Defaults to `csv`. An unknown value is a 422 rather than a silent fallback.', schema: new OA\Schema(type: 'string', enum: ['csv', 'pdf'])),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The shopping list, as a file attachment.',
                content: [
                    new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string')),
                    new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')),
                ],
            ),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function exportShoppingList(Request $request, ShoppingListExporter $exporter, PdfBuilder $pdfBuilder): Response
    {
        [$from, $to] = $this->parser->requireWindow($request);
        $format = $this->parser->exportFormat($request);

        try {
            /** @var ShoppingListDTO $list */
            $list = $this->queryBus->ask(new GetShoppingList($from, $to));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $suffix = sprintf('%s_%s', $list->from, $list->to);

        if ('csv' === $format) {
            return self::attachment(
                CsvBuilder::build(ShoppingListExporter::HEADERS, $exporter->rows($list)),
                'text/csv; charset=UTF-8',
                sprintf('shopping-list-%s.csv', $suffix),
            );
        }

        return self::attachment(
            $pdfBuilder->build('exports/shopping_list_pdf.html.twig', [
                'rows' => $exporter->printableRows($list),
                'from' => $list->from,
                'to' => $list->to,
            ]),
            'application/pdf',
            sprintf('shopping-list-%s.pdf', $suffix),
        );
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        summary: 'Plan a recipe for a slot',
        description: 'A slot holds a list, not a single dish — soup and a main course are an ordinary lunch — so two *different* recipes in one slot are fine. What is refused with 409 is the same recipe twice in the same slot on the same day: that is a double-clicked button, and the shopping list would quietly buy everything twice while the calendar still looked plausible.',
        tags: ['Recipes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['date', 'slot', 'recipeId'],
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-08-05'),
                    new OA\Property(property: 'slot', type: 'string', enum: ['breakfast', 'lunch', 'dinner', 'snack']),
                    new OA\Property(property: 'recipeId', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'servings', type: 'integer', description: 'How many portions to cook, independent of the recipe\'s own. Defaults to 1.', example: 4),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Planned.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 409, ref: '#/components/responses/ConflictError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function planMeal(Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new PlanMeal(
            date: $this->parser->requireString($data, 'date'),
            slot: $this->parser->requireString($data, 'slot'),
            recipeId: $this->parser->requireString($data, 'recipeId'),
            servings: $this->parser->servings($data),
        );

        try {
            $id = $this->commandBus->dispatchAndReturn($command);
        } catch (HandlerFailedException $e) {
            return $this->mapFailure($e);
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    /**
     * PATCH rather than PUT, unlike the recipe update: a move genuinely changes
     * part of the entry (its date and slot) and deliberately leaves the recipe
     * and the servings alone — changing either would make it a different plan
     * rather than the same plan on another day.
     */
    #[Route('/{id}', methods: ['PATCH'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Patch(
        summary: 'Move a planned meal',
        description: 'Relocates the entry to another day and/or slot. Both are always sent — a calendar drag knows exactly where the card landed. Dropping it back where it came from is a no-op success, not a conflict with itself. The recipe and the servings cannot be changed by a move; re-plan instead.',
        tags: ['Recipes'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['date', 'slot'],
                properties: [
                    new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-08-06'),
                    new OA\Property(property: 'slot', type: 'string', enum: ['breakfast', 'lunch', 'dinner', 'snack']),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Moved.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 409, ref: '#/components/responses/ConflictError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function moveMeal(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new MoveMeal(
            id: $id,
            date: $this->parser->requireString($data, 'date'),
            slot: $this->parser->requireString($data, 'slot'),
        );

        try {
            $this->commandBus->dispatch($command);
        } catch (HandlerFailedException $e) {
            return $this->mapFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', methods: ['DELETE'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Delete(
        summary: 'Take a meal off the calendar',
        description: 'Removes one plan entry, identified by its own id rather than by (date, slot, recipe) — a slot holds a list, so the caller is pointing at a row it can already see. Not silently idempotent: a repeat is a 404, because a second click means the list it was made from is stale.',
        tags: ['Recipes'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Unplanned.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function unplanMeal(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new UnplanMeal($id));
        } catch (HandlerFailedException $e) {
            return $this->mapFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private static function attachment(string $body, string $contentType, string $filename): Response
    {
        return new Response($body, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename='.$filename,
        ]);
    }

    private function mapFailure(HandlerFailedException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof PlannedMealNotFoundException || $previous instanceof RecipeNotFoundException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($previous instanceof MealAlreadyPlannedException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_CONFLICT);
        }

        if ($previous instanceof InvalidArgumentException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        throw $e;
    }
}
