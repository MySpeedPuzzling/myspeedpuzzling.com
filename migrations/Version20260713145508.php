<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260713145508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index badge.type for the achievement holders directory';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_FEF0481D8CDE5729 ON badge (type)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_FEF0481D8CDE5729');
    }
}
