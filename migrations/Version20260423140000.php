<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260423140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dispatch_reference to workflow_step_run for delivery tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_step_run ADD dispatch_reference VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workflow_step_run DROP dispatch_reference');
    }
}
