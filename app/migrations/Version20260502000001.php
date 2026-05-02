<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HMAI-46: wipe plaintext Discogs OAuth tokens — re-auth required after upgrade (encryption introduced)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE discogs_oauth_tokens');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE discogs_oauth_tokens');
    }
}
