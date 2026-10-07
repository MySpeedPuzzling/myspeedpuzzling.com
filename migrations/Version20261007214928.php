<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007214928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Outdated puzzle requests: outdated reason + merge that did it, change requests move with a merged puzzle (no cascade), automatic decisions without a decider';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_change_request DROP CONSTRAINT fk_3668c37cd9816812');
        $this->addSql('ALTER TABLE puzzle_change_request ADD outdated_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD merged_from_puzzle_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD CONSTRAINT FK_3668C37CD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD outdated_reason VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD outdated_by_merge_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_moderation_decision ALTER decided_by_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE puzzle_change_request DROP CONSTRAINT FK_3668C37CD9816812');
        $this->addSql('ALTER TABLE puzzle_change_request DROP outdated_reason');
        $this->addSql('ALTER TABLE puzzle_change_request DROP merged_from_puzzle_id');
        $this->addSql('ALTER TABLE puzzle_change_request ADD CONSTRAINT fk_3668c37cd9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP outdated_reason');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP outdated_by_merge_request_id');
        $this->addSql('ALTER TABLE puzzle_moderation_decision ALTER decided_by_id SET NOT NULL');
    }
}
