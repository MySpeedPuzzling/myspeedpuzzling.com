<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260320062525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename puzzle.hide_until to puzzle.hide_image_until';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle RENAME COLUMN hide_until TO hide_image_until');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle RENAME COLUMN hide_image_until TO hide_until');
    }
}
