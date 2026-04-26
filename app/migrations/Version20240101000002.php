<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Articles module table: articles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE articles (
                id VARCHAR(36) NOT NULL,
                title VARCHAR(500) NOT NULL,
                url VARCHAR(768) NOT NULL,
                category VARCHAR(255) DEFAULT NULL,
                estimated_read_time INT DEFAULT NULL,
                added_at DATETIME NOT NULL,
                read_at DATETIME DEFAULT NULL,
                is_read TINYINT(1) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_articles_url (url)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE articles');
    }
}
