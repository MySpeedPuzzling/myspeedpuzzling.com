<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713152816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Composite (email_type, sent_at) index for digest-volume audit queries and retention cleanup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_F9EA5FDE13E877AC96E4F388 ON email_audit_log (email_type, sent_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_F9EA5FDE13E877AC96E4F388');
    }
}
