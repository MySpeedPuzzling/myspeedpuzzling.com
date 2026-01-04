<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251218233751 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add puzzle change request and merge request tables for community feedback system';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE puzzle_change_request (status VARCHAR(255) NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, id UUID NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, proposed_name VARCHAR(255) DEFAULT NULL, proposed_pieces_count INT DEFAULT NULL, proposed_ean VARCHAR(255) DEFAULT NULL, proposed_identification_number VARCHAR(255) DEFAULT NULL, proposed_image VARCHAR(255) DEFAULT NULL, original_name VARCHAR(255) NOT NULL, original_manufacturer_id UUID DEFAULT NULL, original_pieces_count INT NOT NULL, original_ean VARCHAR(255) DEFAULT NULL, original_identification_number VARCHAR(255) DEFAULT NULL, original_image VARCHAR(255) DEFAULT NULL, reviewed_by_id UUID DEFAULT NULL, puzzle_id UUID NOT NULL, reporter_id UUID NOT NULL, proposed_manufacturer_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_3668C37CFC6B21F1 ON puzzle_change_request (reviewed_by_id)');
        $this->addSql('CREATE INDEX IDX_3668C37CD9816812 ON puzzle_change_request (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_3668C37CE1CFE6F5 ON puzzle_change_request (reporter_id)');
        $this->addSql('CREATE INDEX IDX_3668C37C4971441F ON puzzle_change_request (proposed_manufacturer_id)');
        $this->addSql('CREATE TABLE puzzle_merge_request (status VARCHAR(255) NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, survivor_puzzle_id UUID DEFAULT NULL, merged_puzzle_ids JSON DEFAULT \'[]\' NOT NULL, id UUID NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reported_duplicate_puzzle_ids JSON NOT NULL, reviewed_by_id UUID DEFAULT NULL, source_puzzle_id UUID NOT NULL, reporter_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C53156FBFC6B21F1 ON puzzle_merge_request (reviewed_by_id)');
        $this->addSql('CREATE INDEX IDX_C53156FBB11FFBDC ON puzzle_merge_request (source_puzzle_id)');
        $this->addSql('CREATE INDEX IDX_C53156FBE1CFE6F5 ON puzzle_merge_request (reporter_id)');
        $this->addSql('ALTER TABLE puzzle_change_request ADD CONSTRAINT FK_3668C37CFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_change_request ADD CONSTRAINT FK_3668C37CD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_change_request ADD CONSTRAINT FK_3668C37CE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_change_request ADD CONSTRAINT FK_3668C37C4971441F FOREIGN KEY (proposed_manufacturer_id) REFERENCES manufacturer (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD CONSTRAINT FK_C53156FBFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD CONSTRAINT FK_C53156FBB11FFBDC FOREIGN KEY (source_puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE puzzle_merge_request ADD CONSTRAINT FK_C53156FBE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD target_change_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD target_merge_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA96566C4C FOREIGN KEY (target_change_request_id) REFERENCES puzzle_change_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAFB320824 FOREIGN KEY (target_merge_request_id) REFERENCES puzzle_merge_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA96566C4C ON notification (target_change_request_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CAFB320824 ON notification (target_merge_request_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_change_request DROP CONSTRAINT FK_3668C37CFC6B21F1');
        $this->addSql('ALTER TABLE puzzle_change_request DROP CONSTRAINT FK_3668C37CD9816812');
        $this->addSql('ALTER TABLE puzzle_change_request DROP CONSTRAINT FK_3668C37CE1CFE6F5');
        $this->addSql('ALTER TABLE puzzle_change_request DROP CONSTRAINT FK_3668C37C4971441F');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP CONSTRAINT FK_C53156FBFC6B21F1');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP CONSTRAINT FK_C53156FBB11FFBDC');
        $this->addSql('ALTER TABLE puzzle_merge_request DROP CONSTRAINT FK_C53156FBE1CFE6F5');
        $this->addSql('DROP TABLE puzzle_change_request');
        $this->addSql('DROP TABLE puzzle_merge_request');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA96566C4C');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAFB320824');
        $this->addSql('DROP INDEX IDX_BF5476CA96566C4C');
        $this->addSql('DROP INDEX IDX_BF5476CAFB320824');
        $this->addSql('ALTER TABLE notification DROP target_change_request_id');
        $this->addSql('ALTER TABLE notification DROP target_merge_request_id');
    }
}
