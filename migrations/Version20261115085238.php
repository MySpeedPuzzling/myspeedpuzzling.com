<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115085238 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE unlisted_ownership (pairing_id UUID DEFAULT NULL, converted_item_id UUID DEFAULT NULL, converted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, converted_created BOOLEAN DEFAULT false NOT NULL, made JSONB DEFAULT NULL, kept_reason VARCHAR(40) DEFAULT NULL, id UUID NOT NULL, kind VARCHAR(16) NOT NULL, comment TEXT DEFAULT NULL, collection_label TEXT DEFAULT NULL, import_row_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, unlisted_puzzle_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_D85EC268194F46EE ON unlisted_ownership (unlisted_puzzle_id)');
        $this->addSql('CREATE INDEX IDX_D85EC26899E6F5DF ON unlisted_ownership (player_id)');
        $this->addSql('CREATE INDEX IDX_D85EC268FF93D3EA ON unlisted_ownership (import_row_id)');
        $this->addSql('CREATE INDEX IDX_D85EC268DC8046BD ON unlisted_ownership (pairing_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D85EC268194F46EE3BC4BCD9 ON unlisted_ownership (unlisted_puzzle_id, kind)');
        $this->addSql('CREATE TABLE unlisted_pairing (state VARCHAR(16) NOT NULL, last_activity_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, undo_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, undone_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, results_added INT DEFAULT 0 NOT NULL, results_already_yours INT DEFAULT 0 NOT NULL, results_kept INT DEFAULT 0 NOT NULL, items_added INT DEFAULT 0 NOT NULL, items_already_yours INT DEFAULT 0 NOT NULL, results_taken_back INT DEFAULT 0 NOT NULL, results_kept_by_undo INT DEFAULT 0 NOT NULL, id UUID NOT NULL, request_id UUID NOT NULL, puzzle_id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, unlisted_puzzle_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7B95097F99E6F5DF ON unlisted_pairing (player_id)');
        $this->addSql('CREATE INDEX IDX_7B95097F194F46EE ON unlisted_pairing (unlisted_puzzle_id)');
        $this->addSql('CREATE INDEX IDX_7B95097F194F46EED46F4E3 ON unlisted_pairing (unlisted_puzzle_id, started_at)');
        $this->addSql('CREATE INDEX IDX_7B95097FA393D2FB149885F6 ON unlisted_pairing (state, last_activity_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7B95097F427EB8A5 ON unlisted_pairing (request_id)');
        $this->addSql('CREATE TABLE unlisted_puzzle (brand_text TEXT DEFAULT NULL, pieces_count INT DEFAULT NULL, eans JSONB DEFAULT \'[]\' NOT NULL, brand_codes JSONB DEFAULT \'[]\' NOT NULL, picture_url TEXT DEFAULT NULL, paired_puzzle_id UUID DEFAULT NULL, paired_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, suggested_puzzle_id UUID DEFAULT NULL, suggested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, refused_suggestion_puzzle_id UUID DEFAULT NULL, match_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, puzzle_key VARCHAR(80) NOT NULL, name TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_import_id UUID DEFAULT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_183214CD99E6F5DF ON unlisted_puzzle (player_id)');
        $this->addSql('CREATE INDEX IDX_183214CD9662268C ON unlisted_puzzle (match_checked_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_183214CD99E6F5DF1224331A ON unlisted_puzzle (player_id, puzzle_key)');
        $this->addSql('CREATE TABLE unlisted_result (pairing_id UUID DEFAULT NULL, converted_solving_time_id UUID DEFAULT NULL, converted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, converted_created BOOLEAN DEFAULT false NOT NULL, made JSONB DEFAULT NULL, kept_reason VARCHAR(40) DEFAULT NULL, id UUID NOT NULL, seconds_to_solve INT DEFAULT NULL, solved_on VARCHAR(10) DEFAULT NULL, date_precision VARCHAR(8) DEFAULT NULL, puzzling_type VARCHAR(8) NOT NULL, people JSONB DEFAULT \'[]\' NOT NULL, first_attempt BOOLEAN DEFAULT NULL, comment TEXT DEFAULT NULL, import_row_id UUID NOT NULL, source_key VARCHAR(80) NOT NULL, row_number INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, unlisted_puzzle_id UUID NOT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_29FE0A01194F46EE ON unlisted_result (unlisted_puzzle_id)');
        $this->addSql('CREATE INDEX IDX_29FE0A0199E6F5DF ON unlisted_result (player_id)');
        $this->addSql('CREATE INDEX IDX_29FE0A0199E6F5DFDB64AEE8 ON unlisted_result (player_id, source_key)');
        $this->addSql('CREATE INDEX IDX_29FE0A01DC8046BD ON unlisted_result (pairing_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_29FE0A01FF93D3EA ON unlisted_result (import_row_id)');
        $this->addSql('ALTER TABLE unlisted_ownership ADD CONSTRAINT FK_D85EC268194F46EE FOREIGN KEY (unlisted_puzzle_id) REFERENCES unlisted_puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unlisted_ownership ADD CONSTRAINT FK_D85EC26899E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unlisted_pairing ADD CONSTRAINT FK_7B95097F99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unlisted_pairing ADD CONSTRAINT FK_7B95097F194F46EE FOREIGN KEY (unlisted_puzzle_id) REFERENCES unlisted_puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unlisted_puzzle ADD CONSTRAINT FK_183214CD99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unlisted_result ADD CONSTRAINT FK_29FE0A01194F46EE FOREIGN KEY (unlisted_puzzle_id) REFERENCES unlisted_puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unlisted_result ADD CONSTRAINT FK_29FE0A0199E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE unlisted_ownership DROP CONSTRAINT FK_D85EC268194F46EE');
        $this->addSql('ALTER TABLE unlisted_ownership DROP CONSTRAINT FK_D85EC26899E6F5DF');
        $this->addSql('ALTER TABLE unlisted_pairing DROP CONSTRAINT FK_7B95097F99E6F5DF');
        $this->addSql('ALTER TABLE unlisted_pairing DROP CONSTRAINT FK_7B95097F194F46EE');
        $this->addSql('ALTER TABLE unlisted_puzzle DROP CONSTRAINT FK_183214CD99E6F5DF');
        $this->addSql('ALTER TABLE unlisted_result DROP CONSTRAINT FK_29FE0A01194F46EE');
        $this->addSql('ALTER TABLE unlisted_result DROP CONSTRAINT FK_29FE0A0199E6F5DF');
        $this->addSql('DROP TABLE unlisted_ownership');
        $this->addSql('DROP TABLE unlisted_pairing');
        $this->addSql('DROP TABLE unlisted_puzzle');
        $this->addSql('DROP TABLE unlisted_result');
    }
}
