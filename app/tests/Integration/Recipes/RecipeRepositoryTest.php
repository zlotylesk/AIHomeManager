<?php

declare(strict_types=1);

namespace App\Tests\Integration\Recipes;

use App\Module\Recipes\Domain\Entity\Recipe;
use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use App\Module\Recipes\Infrastructure\Persistence\DoctrineRecipeRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Recipe aggregate is persisted with plain DBAL over three tables, so it is
 * deliberately outside `doctrine:schema:validate` — these round trips are what
 * pins the mapping instead.
 */
final class RecipeRepositoryTest extends KernelTestCase
{
    private DoctrineRecipeRepository $repository;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->connection = $em->getConnection();
        $this->repository = new DoctrineRecipeRepository($this->connection);

        $this->connection->executeStatement('TRUNCATE TABLE recipe_ingredients');
        $this->connection->executeStatement('TRUNCATE TABLE recipe_steps');
        $this->connection->executeStatement('TRUNCATE TABLE recipes');
    }

    public function testSaveAndFindByIdRoundTripsEveryField(): void
    {
        $this->repository->save(new Recipe(
            'r0000001-0000-0000-0000-000000000001',
            'Naleśniki z serem',
            [
                new Ingredient('Mąka pszenna', 200.0, MeasurementUnit::GRAM),
                new Ingredient('Mleko', 0.5, MeasurementUnit::LITRE),
                new Ingredient('Jajko', 2.0, MeasurementUnit::PIECE),
            ],
            ['Wymieszaj mąkę z mlekiem', 'Dodaj jajka', 'Smaż na patelni'],
            4,
            30,
            ['śniadanie', 'słodkie'],
        ));

        $found = $this->repository->findById('r0000001-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('Naleśniki z serem', $found->title());
        self::assertSame(4, $found->servings());
        self::assertSame(30, $found->prepTimeMinutes());
        self::assertSame(['śniadanie', 'słodkie'], $found->tags());
        self::assertSame(['Wymieszaj mąkę z mlekiem', 'Dodaj jajka', 'Smaż na patelni'], $found->steps());

        self::assertCount(3, $found->ingredients());
        self::assertSame('Mąka pszenna', $found->ingredients()[0]->name());
        self::assertSame(200.0, $found->ingredients()[0]->quantity());
        self::assertSame(MeasurementUnit::GRAM, $found->ingredients()[0]->unit());
        self::assertSame('Mleko', $found->ingredients()[1]->name());
        self::assertSame(MeasurementUnit::LITRE, $found->ingredients()[1]->unit());
        self::assertSame('Jajko', $found->ingredients()[2]->name());
    }

    /**
     * quantity is stored as DOUBLE so a fractional amount is not truncated to a
     * fixed number of decimal places — a DECIMAL column would round a quantity
     * scaled by a servings ratio, which is exactly what the shopping list does
     * to it. Values a cook actually types round-trip exactly; a non-terminating
     * one keeps ~14 significant digits, because PDO renders the bound float
     * through PHP's `precision` ini rather than its full binary value. That is
     * orders of magnitude finer than any kitchen scale, so it is a documented
     * property rather than a defect — but it is pinned, so a future switch to a
     * coarser column type cannot pass unnoticed.
     */
    public function testFractionalQuantityIsNotTruncatedToFixedDecimals(): void
    {
        $this->repository->save(new Recipe(
            'r0000002-0000-0000-0000-000000000001',
            'Ciasto',
            [
                new Ingredient('Cukier', 0.25, MeasurementUnit::CUP),
                new Ingredient('Mleko', 0.005, MeasurementUnit::LITRE),
                new Ingredient('Mąka', 1 / 3, MeasurementUnit::CUP),
            ],
        ));

        $found = $this->repository->findById('r0000002-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame(0.25, $found->ingredients()[0]->quantity());
        self::assertSame(0.005, $found->ingredients()[1]->quantity());
        self::assertEqualsWithDelta(1 / 3, $found->ingredients()[2]->quantity(), 1.0e-13);
    }

    public function testRecipeWithoutOptionalFieldsHydratesRealNullAndEmptyLists(): void
    {
        $this->repository->save(new Recipe(
            'r0000003-0000-0000-0000-000000000001',
            'Kanapka',
            [new Ingredient('Chleb', 2.0, MeasurementUnit::PIECE)],
        ));

        $found = $this->repository->findById('r0000003-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertNull($found->prepTimeMinutes());
        self::assertSame(1, $found->servings());
        self::assertSame([], $found->steps());
        self::assertSame([], $found->tags());
    }

    public function testFindByIdReturnsNullForUnknownRecipe(): void
    {
        self::assertNull($this->repository->findById('does-not-exist'));
    }

    /**
     * A save replaces the value-object children wholesale — anything else would
     * let an ingredient the user removed survive in the database.
     */
    public function testSavingAgainReplacesIngredientsAndStepsInsteadOfAppending(): void
    {
        $recipe = new Recipe(
            'r0000004-0000-0000-0000-000000000001',
            'Zupa',
            [
                new Ingredient('Marchew', 3.0, MeasurementUnit::PIECE),
                new Ingredient('Ziemniak', 4.0, MeasurementUnit::PIECE),
            ],
            ['Obierz warzywa'],
        );
        $this->repository->save($recipe);

        $recipe->removeIngredient('Ziemniak', MeasurementUnit::PIECE);
        $recipe->addStep('Gotuj 20 minut');
        $recipe->rename('Zupa jarzynowa');
        $this->repository->save($recipe);

        $found = $this->repository->findById('r0000004-0000-0000-0000-000000000001');

        self::assertNotNull($found);
        self::assertSame('Zupa jarzynowa', $found->title());
        self::assertCount(1, $found->ingredients());
        self::assertSame('Marchew', $found->ingredients()[0]->name());
        self::assertSame(['Obierz warzywa', 'Gotuj 20 minut'], $found->steps());

        self::assertSame(1, $this->countRows('recipe_ingredients'));
        self::assertSame(2, $this->countRows('recipe_steps'));
        self::assertSame(1, $this->countRows('recipes'));
    }

    /**
     * The cascade is hand-written, so it is asserted against raw row counts —
     * a delete path that forgets the children has to fail loudly (the Series
     * repository precedent).
     */
    public function testRemoveDeletesIngredientsAndStepsToo(): void
    {
        $recipe = new Recipe(
            'r0000005-0000-0000-0000-000000000001',
            'Omlet',
            [new Ingredient('Jajko', 3.0, MeasurementUnit::PIECE)],
            ['Roztrzep jajka', 'Smaż'],
        );
        $this->repository->save($recipe);

        $this->repository->remove($recipe);

        self::assertNull($this->repository->findById('r0000005-0000-0000-0000-000000000001'));
        self::assertSame(0, $this->countRows('recipes'));
        self::assertSame(0, $this->countRows('recipe_ingredients'));
        self::assertSame(0, $this->countRows('recipe_steps'));
    }

    public function testRemovingOneRecipeLeavesAnotherRecipesChildrenIntact(): void
    {
        $kept = new Recipe(
            'r0000006-0000-0000-0000-000000000001',
            'Sałatka',
            [new Ingredient('Pomidor', 2.0, MeasurementUnit::PIECE)],
            ['Pokrój'],
        );
        $removed = new Recipe(
            'r0000006-0000-0000-0000-000000000002',
            'Tost',
            [new Ingredient('Chleb', 2.0, MeasurementUnit::PIECE)],
            ['Opiecz'],
        );
        $this->repository->save($kept);
        $this->repository->save($removed);

        $this->repository->remove($removed);

        $found = $this->repository->findById('r0000006-0000-0000-0000-000000000001');
        self::assertNotNull($found);
        self::assertCount(1, $found->ingredients());
        self::assertSame(['Pokrój'], $found->steps());
    }

    public function testFindAllReturnsEveryRecipeWithItsOwnChildrenOrderedByTitle(): void
    {
        $this->repository->save(new Recipe(
            'r0000007-0000-0000-0000-000000000001',
            'Zapiekanka',
            [new Ingredient('Bagietka', 1.0, MeasurementUnit::PIECE)],
            ['Zapiecz'],
        ));
        $this->repository->save(new Recipe(
            'r0000007-0000-0000-0000-000000000002',
            'Anielskie ciasto',
            [
                new Ingredient('Mąka', 300.0, MeasurementUnit::GRAM),
                new Ingredient('Cukier', 150.0, MeasurementUnit::GRAM),
            ],
            ['Ubij białka', 'Piecz 40 minut'],
            8,
        ));

        $all = $this->repository->findAll();

        self::assertCount(2, $all);
        self::assertSame('Anielskie ciasto', $all[0]->title());
        self::assertCount(2, $all[0]->ingredients());
        self::assertSame(['Ubij białka', 'Piecz 40 minut'], $all[0]->steps());
        self::assertSame(8, $all[0]->servings());
        self::assertSame('Zapiekanka', $all[1]->title());
        self::assertCount(1, $all[1]->ingredients());
        self::assertSame(['Zapiecz'], $all[1]->steps());
    }

    public function testFindAllReturnsEmptyArrayWhenThereAreNoRecipes(): void
    {
        self::assertSame([], $this->repository->findAll());
    }

    /**
     * Reading rebuilds the aggregate through its real constructor, so a row
     * that could never have been written by the aggregate is refused rather
     * than hydrated into a Recipe whose invariants never ran. This is the whole
     * reason the module is not ORM-mapped: Doctrine hydrates by bypassing the
     * constructor, which would make the persisted recipes the only ones in the
     * system nobody had validated.
     */
    public function testReadingRefusesARowThatViolatesTheAggregatesInvariant(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO recipes (id, title, servings, prep_time_minutes, tags) VALUES ('r-corrupted', 'Przepis bez składników', 1, NULL, '[]')",
        );

        $this->expectException(InvalidArgumentException::class);

        $this->repository->findById('r-corrupted');
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $table));
    }
}
