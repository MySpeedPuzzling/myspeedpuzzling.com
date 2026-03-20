<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320062525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add puzzle.hide_image_until column';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle ADD hide_image_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle DROP hide_image_until');
    }
}
