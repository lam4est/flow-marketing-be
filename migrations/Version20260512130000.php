<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Contacts must have email + phone; content templates must have a subject line.
 */
final class Version20260512130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Require non-null contact.email and contact.phone; require non-null content_template.subject.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE contact SET email = 'user_' || id::text || '@placeholder.invalid' WHERE email IS NULL OR TRIM(BOTH FROM email) = ''");
        $this->addSql("UPDATE contact SET phone = '+120000000' || LPAD(MOD(id, 1000000)::text, 6, '0') WHERE phone IS NULL OR TRIM(BOTH FROM phone) = ''");

        $this->addSql('ALTER TABLE contact ALTER COLUMN email SET NOT NULL');
        $this->addSql('ALTER TABLE contact ALTER COLUMN phone SET NOT NULL');

        $this->addSql("UPDATE content_template SET subject = TRIM(BOTH FROM name) WHERE subject IS NULL OR TRIM(BOTH FROM subject) = ''");
        $this->addSql('ALTER TABLE content_template ALTER COLUMN subject SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_template ALTER COLUMN subject DROP NOT NULL');
        $this->addSql('ALTER TABLE contact ALTER COLUMN phone DROP NOT NULL');
        $this->addSql('ALTER TABLE contact ALTER COLUMN email DROP NOT NULL');
    }
}
