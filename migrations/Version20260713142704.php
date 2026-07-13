<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713142704 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'First-click badge reveal moment (badge.revealed_at)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE badge ADD revealed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE badge DROP revealed_at');
    }
}
