<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create meal_plan table for the Recipes meal-planning model (HMAI-389)';
    }

    public function up(Schema $schema): void
    {
        // One row per planned recipe, so a slot may hold several — soup and a
        // main course are one "obiad". The unique constraint therefore guards
        // the same recipe twice in one slot (a double-clicked add, which the
        // shopping list would silently buy for twice), not two recipes sharing
        // a slot. `date` is the leftmost column, so the calendar's date-range
        // read uses this index and needs no second one of its own.
        $this->addSql(<<<'SQL'
            CREATE TABLE meal_plan (
                id VARCHAR(36) NOT NULL,
                date DATE NOT NULL,
                slot VARCHAR(20) NOT NULL,
                recipe_id VARCHAR(36) NOT NULL,
                servings INT NOT NULL,
                UNIQUE INDEX uniq_meal_plan_date_slot_recipe (date, slot, recipe_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE meal_plan');
    }
}
