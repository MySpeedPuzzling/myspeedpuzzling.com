<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251127141250 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sell/swap list feature tables and player settings';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sell_swap_list_item (id UUID NOT NULL, listing_type VARCHAR(255) NOT NULL, price DOUBLE PRECISION DEFAULT NULL, condition VARCHAR(255) NOT NULL, comment TEXT DEFAULT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, player_id UUID NOT NULL, puzzle_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_48B1ACB299E6F5DF ON sell_swap_list_item (player_id)');
        $this->addSql('CREATE INDEX IDX_48B1ACB2D9816812 ON sell_swap_list_item (puzzle_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_48B1ACB299E6F5DFD9816812 ON sell_swap_list_item (player_id, puzzle_id)');
        $this->addSql('CREATE TABLE sold_swapped_item (id UUID NOT NULL, buyer_name VARCHAR(255) DEFAULT NULL, listing_type VARCHAR(255) NOT NULL, price DOUBLE PRECISION DEFAULT NULL, sold_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, seller_id UUID NOT NULL, puzzle_id UUID NOT NULL, buyer_player_id UUID DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_DDC625EF8DE820D9 ON sold_swapped_item (seller_id)');
        $this->addSql('CREATE INDEX IDX_DDC625EFD9816812 ON sold_swapped_item (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_DDC625EF4F89904F ON sold_swapped_item (buyer_player_id)');
        $this->addSql('ALTER TABLE sell_swap_list_item ADD CONSTRAINT FK_48B1ACB299E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sell_swap_list_item ADD CONSTRAINT FK_48B1ACB2D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sold_swapped_item ADD CONSTRAINT FK_DDC625EF8DE820D9 FOREIGN KEY (seller_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sold_swapped_item ADD CONSTRAINT FK_DDC625EFD9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE sold_swapped_item ADD CONSTRAINT FK_DDC625EF4F89904F FOREIGN KEY (buyer_player_id) REFERENCES player (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE player ADD sell_swap_list_settings JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sell_swap_list_item DROP CONSTRAINT FK_48B1ACB299E6F5DF');
        $this->addSql('ALTER TABLE sell_swap_list_item DROP CONSTRAINT FK_48B1ACB2D9816812');
        $this->addSql('ALTER TABLE sold_swapped_item DROP CONSTRAINT FK_DDC625EF8DE820D9');
        $this->addSql('ALTER TABLE sold_swapped_item DROP CONSTRAINT FK_DDC625EFD9816812');
        $this->addSql('ALTER TABLE sold_swapped_item DROP CONSTRAINT FK_DDC625EF4F89904F');
        $this->addSql('DROP TABLE sell_swap_list_item');
        $this->addSql('DROP TABLE sold_swapped_item');
        $this->addSql('ALTER TABLE player DROP sell_swap_list_settings');
    }
}
