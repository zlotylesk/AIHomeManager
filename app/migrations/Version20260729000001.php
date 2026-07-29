<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create budget_transactions and budget_categories tables for the Budget module (HMAI-376)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_categories (
                id VARCHAR(36) NOT NULL,
                name VARCHAR(255) NOT NULL,
                type VARCHAR(20) NOT NULL,
                monthly_limit VARCHAR(32) DEFAULT NULL,
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE budget_transactions (
                id VARCHAR(36) NOT NULL,
                amount VARCHAR(32) NOT NULL,
                date DATE NOT NULL,
                category_id VARCHAR(36) NOT NULL,
                type VARCHAR(20) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                INDEX idx_budget_transactions_category_id (category_id),
                INDEX idx_budget_transactions_date (date),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE budget_transactions');
        $this->addSql('DROP TABLE budget_categories');
    }
}
