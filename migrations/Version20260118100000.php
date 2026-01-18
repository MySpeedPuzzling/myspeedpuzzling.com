<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unboxed column to puzzle_solving_time table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_solving_time ADD unboxed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_solving_time DROP unboxed');
    }
}
