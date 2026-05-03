<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260503000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HMAI-47: wipe plaintext Google OAuth tokens — re-auth required after upgrade (encryption introduced)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE google_oauth_tokens');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('TRUNCATE TABLE google_oauth_tokens');
    }
}
