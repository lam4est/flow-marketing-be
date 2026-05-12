<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * New workflow step enrollments should start inactive until the user turns them on.
 */
final class Version20260512120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Default workflow_step_user.is_active to false at DB level.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_step_user ALTER COLUMN is_active SET DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_step_user ALTER COLUMN is_active SET DEFAULT true');
    }
}
