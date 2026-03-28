<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328141426 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image_ratio to puzzle and proposed_image_ratio to puzzle_change_request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle ADD image_ratio DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD proposed_image_ratio DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle DROP image_ratio');
        $this->addSql('ALTER TABLE puzzle_change_request DROP proposed_image_ratio');
    }
}
