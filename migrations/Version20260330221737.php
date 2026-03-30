<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260330221737 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_difficulty ADD indices_p25 DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_difficulty ADD indices_p75 DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_difficulty DROP indices_p25');
        $this->addSql('ALTER TABLE puzzle_difficulty DROP indices_p75');
    }
}
