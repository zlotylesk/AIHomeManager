<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Controller\Recipes\RecipesRequestParser;
use App\Messaging\CommandBus;
use App\Messaging\QueryBus;
use App\Module\Recipes\Application\Command\CreateRecipe;
use App\Module\Recipes\Application\Command\DeleteRecipe;
use App\Module\Recipes\Application\Command\UpdateRecipe;
use App\Module\Recipes\Application\DTO\RecipeDetailDTO;
use App\Module\Recipes\Application\DTO\RecipeDTO;
use App\Module\Recipes\Application\DTO\RecipeIngredientDTO;
use App\Module\Recipes\Application\Exception\RecipeIsPlannedException;
use App\Module\Recipes\Application\Exception\RecipeNotFoundException;
use App\Module\Recipes\Application\Query\GetRecipeDetail;
use App\Module\Recipes\Application\Query\GetRecipes;
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
 * Thin REST surface for the recipe catalog: reads via query.bus, writes via
 * command.bus, no domain logic (payload shape-checking lives in
 * RecipesRequestParser). Version-agnostic path — served under /api/v1/recipes
 * and the /api/recipes alias (ADR-008).
 */
#[Route('/recipes')]
final class RecipesController extends AbstractController
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
        summary: 'List the recipe catalog',
        description: 'Every recipe, ordered by title. The two filters are independent and combine with AND; a blank value is treated as no filter rather than as a search for the empty string. `tag` is an exact, case-insensitive match on one tag — not a prefix search, so `obiad` does not match `obiadowy`. Each entry carries an `ingredientCount` rather than the ingredients themselves; fetch the detail to get those.',
        tags: ['Recipes'],
        parameters: [
            new OA\Parameter(name: 'tag', in: 'query', required: false, description: 'Exact tag to filter by, matched case-insensitively.', schema: new OA\Schema(type: 'string', example: 'obiad')),
            new OA\Parameter(name: 'phrase', in: 'query', required: false, description: 'Substring of the title. Wildcards typed by the user are escaped, so `100%` matches a literal per-cent sign.', schema: new OA\Schema(type: 'string', example: 'naleśniki')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'The matching recipes, ordered by title.',
                content: new OA\JsonContent(type: 'array', items: new OA\Items(ref: new Model(type: RecipeDTO::class))),
            ),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function list(Request $request): JsonResponse
    {
        /** @var RecipeDTO[] $recipes */
        $recipes = $this->queryBus->ask(new GetRecipes(
            tag: $this->parser->optionalQuery($request, 'tag'),
            phrase: $this->parser->optionalQuery($request, 'phrase'),
        ));

        return new JsonResponse($this->normalizer->normalize($recipes));
    }

    #[Route('/{id}', methods: ['GET'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Get(
        summary: 'One recipe with its ingredients and steps',
        description: "The recipe's own fields flattened to the top level, plus its ingredients and steps in the order they were saved. Quantities are the raw stored values; the `unit` is a canonical identifier (`g`, `ml`, `tablespoon`), never a display label.",
        tags: ['Recipes'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            // allOf, not a $ref to RecipeDetailDTO: the normalizer FLATTENS the
            // recipe's fields to the top level and appends the two collections,
            // so the DTO's own `recipe` property never appears in the JSON (the
            // BookDetailDTO / PodcastDetailDTO precedent — the runtime
            // conformance gate catches the difference).
            new OA\Response(
                response: 200,
                description: 'The recipe, its ingredients and its steps in order.',
                content: new OA\JsonContent(allOf: [
                    new OA\Schema(ref: new Model(type: RecipeDTO::class)),
                    new OA\Schema(properties: [
                        new OA\Property(property: 'ingredients', type: 'array', items: new OA\Items(ref: new Model(type: RecipeIngredientDTO::class))),
                        new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'string')),
                    ]),
                ]),
            ),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function detail(string $id): JsonResponse
    {
        $recipe = $this->queryBus->ask(new GetRecipeDetail($id));

        if (!$recipe instanceof RecipeDetailDTO) {
            return new JsonResponse(['error' => sprintf('Recipe "%s" not found.', $id)], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->normalizer->normalize($recipe));
    }

    #[Route('', methods: ['POST'])]
    #[OA\Post(
        summary: 'Create a recipe',
        description: 'At least one ingredient is required — a recipe with none contributes nothing to a shopping list and cannot be cooked from. Two lines sharing the same (name, unit) pair are rejected rather than merged, because that is the identity the shopping list aggregates on. Steps may be empty: some recipes really are "mix it all together".',
        tags: ['Recipes'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'ingredients'],
                properties: [
                    new OA\Property(property: 'title', type: 'string', example: 'Naleśniki'),
                    new OA\Property(
                        property: 'ingredients',
                        type: 'array',
                        description: 'At least one. The `unit` is a canonical identifier, never a label — an unknown one is rejected rather than defaulted.',
                        items: new OA\Items(ref: new Model(type: RecipeIngredientDTO::class)),
                    ),
                    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'servings', type: 'integer', description: 'At least 1. Defaults to 1.', example: 4),
                    new OA\Property(property: 'prepTimeMinutes', type: ['integer', 'null'], example: 30),
                    new OA\Property(property: 'tags', type: 'array', description: 'Lower-cased and de-duplicated on save.', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created.',
                content: new OA\JsonContent(properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid')]),
            ),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function create(Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new CreateRecipe(
            title: $this->parser->requireTitle($data),
            ingredients: $this->parser->requireIngredients($data),
            steps: $this->parser->stringList($data, 'steps'),
            servings: $this->parser->servings($data),
            prepTimeMinutes: $this->parser->optionalPrepTime($data),
            tags: $this->parser->stringList($data, 'tags'),
        );

        try {
            $id = $this->commandBus->dispatchAndReturn($command);
        } catch (HandlerFailedException $e) {
            return $this->mapFailure($e);
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    /**
     * PUT rather than PATCH, because the update is a full replace: an
     * ingredient list is a set the user rewrites, not a log of add/remove
     * moves, so an omitted field would be indistinguishable from a deliberate
     * clearing. PATCH would advertise partial semantics the command does not
     * have, and a client sending only `{title}` would be surprised either by a
     * wiped ingredient list or by a 422 that looks like an API bug.
     */
    #[Route('/{id}', methods: ['PUT'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    #[OA\Put(
        summary: 'Replace a recipe',
        description: 'A full replace, not a partial merge: every field is sent and an omitted one is not preserved. A rejected update leaves the stored recipe exactly as it was.',
        tags: ['Recipes'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'ingredients', 'servings'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'ingredients', type: 'array', items: new OA\Items(ref: new Model(type: RecipeIngredientDTO::class))),
                    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'servings', type: 'integer', description: 'Required here, unlike on create: the shopping list scales every quantity by planned/recipe servings, so a silently defaulted value would distort the whole list.'),
                    new OA\Property(property: 'prepTimeMinutes', type: ['integer', 'null']),
                    new OA\Property(property: 'tags', type: 'array', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 204, description: 'Replaced.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 422, ref: '#/components/responses/UnprocessableEntityError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function update(string $id, Request $request): JsonResponse
    {
        $data = $this->parser->decode($request);
        $command = new UpdateRecipe(
            id: $id,
            title: $this->parser->requireTitle($data),
            ingredients: $this->parser->requireIngredients($data),
            steps: $this->parser->stringList($data, 'steps'),
            servings: $this->parser->requireServings($data),
            prepTimeMinutes: $this->parser->optionalPrepTime($data),
            tags: $this->parser->stringList($data, 'tags'),
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
        summary: 'Delete a recipe',
        description: 'Refused with 409 while any meal is still planned from this recipe, anywhere on the calendar — including in the past. Deleting it would leave the plan pointing at nothing, and the shopping list (which joins the plan to the recipes) would come back silently short by that meal, which is a wrong answer that looks right. Remove the plan entries first.',
        tags: ['Recipes'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted.'),
            new OA\Response(response: 404, ref: '#/components/responses/NotFoundError'),
            new OA\Response(response: 409, ref: '#/components/responses/ConflictError'),
            new OA\Response(response: 401, ref: '#/components/responses/UnauthorizedError'),
        ],
    )]
    public function delete(string $id): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new DeleteRecipe($id));
        } catch (HandlerFailedException $e) {
            return $this->mapFailure($e);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function mapFailure(HandlerFailedException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof RecipeNotFoundException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_NOT_FOUND);
        }

        if ($previous instanceof RecipeIsPlannedException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_CONFLICT);
        }

        if ($previous instanceof InvalidArgumentException) {
            return new JsonResponse(['error' => $previous->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        throw $e;
    }
}
