<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Recipes\Domain;

use App\Module\Recipes\Domain\Entity\Recipe;
use App\Module\Recipes\Domain\Enum\MeasurementUnit;
use App\Module\Recipes\Domain\ValueObject\Ingredient;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RecipeTest extends TestCase
{
    public function testConstructsWithEveryField(): void
    {
        $recipe = new Recipe(
            'recipe-1',
            'Naleśniki',
            [new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM)],
            ['Wymieszaj składniki', 'Smaż na patelni'],
            4,
            30,
            ['śniadanie', 'słodkie'],
        );

        self::assertSame('recipe-1', $recipe->id());
        self::assertSame('Naleśniki', $recipe->title());
        self::assertCount(1, $recipe->ingredients());
        self::assertSame(['Wymieszaj składniki', 'Smaż na patelni'], $recipe->steps());
        self::assertSame(4, $recipe->servings());
        self::assertSame(30, $recipe->prepTimeMinutes());
        self::assertSame(['śniadanie', 'słodkie'], $recipe->tags());
    }

    public function testDefaultsToOneServingNoPrepTimeNoStepsNoTags(): void
    {
        $recipe = $this->recipe();

        self::assertSame(1, $recipe->servings());
        self::assertNull($recipe->prepTimeMinutes());
        self::assertSame([], $recipe->steps());
        self::assertSame([], $recipe->tags());
    }

    public function testRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Recipe('  ', 'Naleśniki', [new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM)]);
    }

    /**
     * A recipe with nothing in it contributes nothing to the shopping list and
     * cannot be cooked from — there is no useful state in which it is empty.
     */
    public function testRejectsRecipeWithoutIngredients(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Recipe('recipe-1', 'Naleśniki', []);
    }

    public function testRejectsEmptyTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Recipe('recipe-1', '   ', [new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM)]);
    }

    public function testRejectsTooLongTitle(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Recipe('recipe-1', str_repeat('a', Recipe::MAX_TITLE_LENGTH + 1), [new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM)]);
    }

    public function testRejectsServingsBelowOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Recipe('recipe-1', 'Naleśniki', [new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM)], [], 0);
    }

    public function testRejectsNonPositivePrepTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Recipe('recipe-1', 'Naleśniki', [new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM)], [], 1, 0);
    }

    public function testRenameReplacesTitle(): void
    {
        $recipe = $this->recipe();

        $recipe->rename('  Placki  ');

        self::assertSame('Placki', $recipe->title());
    }

    public function testRenameRejectsEmptyTitle(): void
    {
        $recipe = $this->recipe();

        $this->expectException(InvalidArgumentException::class);

        $recipe->rename('');
    }

    public function testSetServings(): void
    {
        $recipe = $this->recipe();

        $recipe->setServings(6);

        self::assertSame(6, $recipe->servings());
    }

    public function testSetServingsRejectsZero(): void
    {
        $recipe = $this->recipe();

        $this->expectException(InvalidArgumentException::class);

        $recipe->setServings(0);
    }

    public function testAddIngredientAppends(): void
    {
        $recipe = $this->recipe();

        $recipe->addIngredient(new Ingredient('Mleko', 250.0, MeasurementUnit::MILLILITRE));

        self::assertCount(2, $recipe->ingredients());
        self::assertSame('Mleko', $recipe->ingredients()[1]->name());
    }

    /**
     * Identity is (name, unit) — the pair the shopping list groups on — so a
     * second line for a pair already present is a mistake, not a second item.
     */
    public function testAddIngredientRejectsSameNameAndUnit(): void
    {
        $recipe = $this->recipe();

        $this->expectException(InvalidArgumentException::class);

        $recipe->addIngredient(new Ingredient('  mąka  ', 50.0, MeasurementUnit::GRAM));
    }

    public function testAddIngredientAllowsSameNameInDifferentUnit(): void
    {
        $recipe = $this->recipe();

        $recipe->addIngredient(new Ingredient('Mąka', 1.0, MeasurementUnit::CUP));

        self::assertCount(2, $recipe->ingredients());
    }

    public function testRemoveIngredient(): void
    {
        $recipe = $this->recipe();
        $recipe->addIngredient(new Ingredient('Mleko', 250.0, MeasurementUnit::MILLILITRE));

        $recipe->removeIngredient('mleko', MeasurementUnit::MILLILITRE);

        self::assertCount(1, $recipe->ingredients());
        self::assertSame('Mąka', $recipe->ingredients()[0]->name());
    }

    public function testRemoveIngredientReindexesTheList(): void
    {
        $recipe = $this->recipe();
        $recipe->addIngredient(new Ingredient('Mleko', 250.0, MeasurementUnit::MILLILITRE));
        $recipe->addIngredient(new Ingredient('Jajko', 2.0, MeasurementUnit::PIECE));

        $recipe->removeIngredient('Mąka', MeasurementUnit::GRAM);

        self::assertSame([0, 1], array_keys($recipe->ingredients()));
    }

    public function testRemoveIngredientRejectsUnknownIngredient(): void
    {
        $recipe = $this->recipe();

        $this->expectException(InvalidArgumentException::class);

        $recipe->removeIngredient('Cukier', MeasurementUnit::GRAM);
    }

    public function testRemoveIngredientRefusesToEmptyTheRecipe(): void
    {
        $recipe = $this->recipe();

        $this->expectException(InvalidArgumentException::class);

        $recipe->removeIngredient('Mąka', MeasurementUnit::GRAM);
    }

    public function testAddStepAppendsInOrder(): void
    {
        $recipe = $this->recipe();

        $recipe->addStep('  Wymieszaj  ');
        $recipe->addStep('Smaż');

        self::assertSame(['Wymieszaj', 'Smaż'], $recipe->steps());
    }

    public function testAddStepRejectsEmptyText(): void
    {
        $recipe = $this->recipe();

        $this->expectException(InvalidArgumentException::class);

        $recipe->addStep('   ');
    }

    public function testAddStepRejectsTooLongText(): void
    {
        $recipe = $this->recipe();

        $this->expectException(InvalidArgumentException::class);

        $recipe->addStep(str_repeat('a', Recipe::MAX_STEP_LENGTH + 1));
    }

    public function testRetagNormalizesLowercasesAndDeduplicates(): void
    {
        $recipe = $this->recipe();

        $recipe->retag(['Obiad', '  OBIAD  ', 'Wegetariańskie', '']);

        self::assertSame(['obiad', 'wegetariańskie'], $recipe->tags());
    }

    public function testRetagReplacesPreviousTags(): void
    {
        $recipe = $this->recipe();
        $recipe->retag(['obiad']);

        $recipe->retag(['kolacja']);

        self::assertSame(['kolacja'], $recipe->tags());
    }

    /**
     * PHP casts a numeric string used as an array key to int, so de-duplicating
     * tags through array keys would turn "2024" into 2024 — serializing as a
     * JSON number and never matching the string the tag filter searches for.
     */
    public function testRetagKeepsNumericTagsAsStrings(): void
    {
        $recipe = $this->recipe();

        $recipe->retag(['2024', '30', 'obiad']);

        self::assertSame(['2024', '30', 'obiad'], $recipe->tags());
        self::assertSame('["2024","30","obiad"]', json_encode($recipe->tags(), JSON_THROW_ON_ERROR));
    }

    public function testRetagDeduplicatesNumericTags(): void
    {
        $recipe = $this->recipe();

        $recipe->retag(['2024', ' 2024 ', '2024']);

        self::assertSame(['2024'], $recipe->tags());
    }

    public function testRetagRejectsTooLongTag(): void
    {
        $recipe = $this->recipe();

        $this->expectException(InvalidArgumentException::class);

        $recipe->retag([str_repeat('a', Recipe::MAX_TAG_LENGTH + 1)]);
    }

    private function recipe(): Recipe
    {
        return new Recipe('recipe-1', 'Naleśniki', [new Ingredient('Mąka', 200.0, MeasurementUnit::GRAM)]);
    }
}
