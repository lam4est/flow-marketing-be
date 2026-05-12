<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-step excluded contacts (contact ids), distinct from excluded_segment_id (whole contact list).
 */
final class Version20260513100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workflow_step_user.excluded_contact_ids JSON for per-contact exclusions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_step_user ADD excluded_contact_ids JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_step_user DROP COLUMN excluded_contact_ids');
    }
}
