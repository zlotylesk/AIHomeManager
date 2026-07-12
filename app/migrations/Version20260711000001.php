<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create search_documents FULLTEXT index table for the Search engine (HMAI-268)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE search_documents (
                type VARCHAR(20) NOT NULL,
                source_id VARCHAR(191) NOT NULL,
                title VARCHAR(500) NOT NULL,
                content TEXT NOT NULL,
                url VARCHAR(500) NOT NULL,
                PRIMARY KEY (type, source_id),
                FULLTEXT INDEX ft_search_documents (title, content)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE search_documents');
    }
}
