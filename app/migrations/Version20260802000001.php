<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260802000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create recipes, recipe_ingredients and recipe_steps tables for the Recipes module (HMAI-386)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE recipes (
                id VARCHAR(36) NOT NULL,
                title VARCHAR(255) NOT NULL,
                servings INT NOT NULL,
                prep_time_minutes INT DEFAULT NULL,
                tags JSON NOT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // (recipe_id, position) is the primary key rather than a surrogate id:
        // an ingredient is a value object whose only identity within a recipe
        // IS its position, and the composite key doubles as the lookup index.
        // quantity is DOUBLE, not DECIMAL, because the domain deliberately
        // holds a float (0.5 l, a third of a batch) and treats rounding as a
        // presentation concern — a DECIMAL would round a scaled quantity to a
        // fixed number of decimals at write time. DOUBLE keeps ~14 significant
        // digits through the PDO round trip, far finer than any kitchen scale.
        $this->addSql(<<<'SQL'
            CREATE TABLE recipe_ingredients (
                recipe_id VARCHAR(36) NOT NULL,
                `position` INT NOT NULL,
                name VARCHAR(120) NOT NULL,
                quantity DOUBLE PRECISION NOT NULL,
                unit VARCHAR(20) NOT NULL,
                PRIMARY KEY (recipe_id, `position`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE recipe_steps (
                recipe_id VARCHAR(36) NOT NULL,
                `position` INT NOT NULL,
                text VARCHAR(1000) NOT NULL,
                PRIMARY KEY (recipe_id, `position`)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE recipe_steps');
        $this->addSql('DROP TABLE recipe_ingredients');
        $this->addSql('DROP TABLE recipes');
    }
}
