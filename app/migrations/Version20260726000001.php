<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a title-only FULLTEXT index so the FULLTEXT engine can boost title matches (HMAI-364)';
    }

    public function up(Schema $schema): void
    {
        // The existing ft_search_documents index spans (title, content) and can
        // only score the pair. Scoring the title on its own — which is what a
        // field boost is — needs its own index; MySQL rejects a MATCH whose
        // column list has no exactly matching FULLTEXT index.
        $this->addSql('ALTER TABLE search_documents ADD FULLTEXT INDEX ft_search_documents_title (title)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE search_documents DROP INDEX ft_search_documents_title');
    }
}
