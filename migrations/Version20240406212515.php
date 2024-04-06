<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240406212515 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA94BEBE03');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA94BEBE03 FOREIGN KEY (target_solving_time_id) REFERENCES puzzle_solving_time (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT FK_FE83A93CD9816812');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT FK_FE83A93CD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stopwatch DROP CONSTRAINT FK_E7C8F822D9816812');
        $this->addSql('ALTER TABLE stopwatch ADD CONSTRAINT FK_E7C8F822D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE puzzle_solving_time DROP CONSTRAINT fk_fe83a93cd9816812');
        $this->addSql('ALTER TABLE puzzle_solving_time ADD CONSTRAINT fk_fe83a93cd9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT fk_bf5476ca94bebe03');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT fk_bf5476ca94bebe03 FOREIGN KEY (target_solving_time_id) REFERENCES puzzle_solving_time (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stopwatch DROP CONSTRAINT fk_e7c8f822d9816812');
        $this->addSql('ALTER TABLE stopwatch ADD CONSTRAINT fk_e7c8f822d9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
