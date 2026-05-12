<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marketing blast tables removed; automation uses campaign workflow only.
 */
final class Version20260507120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop marketing_campaign_send and marketing_campaign.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS marketing_campaign_send CASCADE');
        $this->addSql('DROP TABLE IF EXISTS marketing_campaign CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Restore from earlier migrations if needed.');
    }
}
