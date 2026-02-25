<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260225095212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_statistics ADD average_time_first_attempt INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD average_time_first_attempt_solo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD average_time_first_attempt_duo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD average_time_first_attempt_team INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD fastest_time_first_attempt INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD fastest_time_first_attempt_solo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD fastest_time_first_attempt_duo INT DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_statistics ADD fastest_time_first_attempt_team INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_statistics DROP average_time_first_attempt');
        $this->addSql('ALTER TABLE puzzle_statistics DROP average_time_first_attempt_solo');
        $this->addSql('ALTER TABLE puzzle_statistics DROP average_time_first_attempt_duo');
        $this->addSql('ALTER TABLE puzzle_statistics DROP average_time_first_attempt_team');
        $this->addSql('ALTER TABLE puzzle_statistics DROP fastest_time_first_attempt');
        $this->addSql('ALTER TABLE puzzle_statistics DROP fastest_time_first_attempt_solo');
        $this->addSql('ALTER TABLE puzzle_statistics DROP fastest_time_first_attempt_duo');
        $this->addSql('ALTER TABLE puzzle_statistics DROP fastest_time_first_attempt_team');
    }
}
