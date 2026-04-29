<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240101000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create books and book_reading_sessions tables for Books module';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE books (
                id VARCHAR(36) NOT NULL,
                isbn VARCHAR(13) NOT NULL,
                title VARCHAR(500) NOT NULL,
                author VARCHAR(255) NOT NULL,
                publisher VARCHAR(255) NOT NULL,
                year INT NOT NULL,
                cover_url VARCHAR(1000) NULL,
                total_pages INT NOT NULL,
                current_page INT NOT NULL,
                status VARCHAR(50) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE INDEX uniq_books_isbn (isbn),
                INDEX idx_books_status (status)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE book_reading_sessions (
                id VARCHAR(36) NOT NULL,
                book_id VARCHAR(36) NOT NULL,
                date DATE NOT NULL,
                pages_read INT NOT NULL,
                notes TEXT NULL,
                PRIMARY KEY (id),
                INDEX idx_reading_sessions_book_id (book_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE book_reading_sessions');
        $this->addSql('DROP TABLE books');
    }
}
