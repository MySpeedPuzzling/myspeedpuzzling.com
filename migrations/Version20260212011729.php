<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260212011729 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE transaction_rating (id UUID NOT NULL, stars SMALLINT NOT NULL, review_text TEXT DEFAULT NULL, rated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reviewer_role VARCHAR(255) NOT NULL, sold_swapped_item_id UUID NOT NULL, reviewer_id UUID NOT NULL, reviewed_player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AEF454625E0BE512 ON transaction_rating (sold_swapped_item_id)');
        $this->addSql('CREATE INDEX IDX_AEF4546270574616 ON transaction_rating (reviewer_id)');
        $this->addSql('CREATE INDEX IDX_AEF454623CA25AFB ON transaction_rating (reviewed_player_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_AEF454625E0BE51270574616 ON transaction_rating (sold_swapped_item_id, reviewer_id)');
        $this->addSql('ALTER TABLE transaction_rating ADD CONSTRAINT FK_AEF454625E0BE512 FOREIGN KEY (sold_swapped_item_id) REFERENCES sold_swapped_item (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transaction_rating ADD CONSTRAINT FK_AEF4546270574616 FOREIGN KEY (reviewer_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE transaction_rating ADD CONSTRAINT FK_AEF454623CA25AFB FOREIGN KEY (reviewed_player_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD target_sold_swapped_item_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA343E61B1 FOREIGN KEY (target_sold_swapped_item_id) REFERENCES sold_swapped_item (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA343E61B1 ON notification (target_sold_swapped_item_id)');
        $this->addSql('ALTER TABLE player ADD rating_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE player ADD average_rating NUMERIC(3, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transaction_rating DROP CONSTRAINT FK_AEF454625E0BE512');
        $this->addSql('ALTER TABLE transaction_rating DROP CONSTRAINT FK_AEF4546270574616');
        $this->addSql('ALTER TABLE transaction_rating DROP CONSTRAINT FK_AEF454623CA25AFB');
        $this->addSql('DROP TABLE transaction_rating');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA343E61B1');
        $this->addSql('DROP INDEX IDX_BF5476CA343E61B1');
        $this->addSql('ALTER TABLE notification DROP target_sold_swapped_item_id');
        $this->addSql('ALTER TABLE player DROP rating_count');
        $this->addSql('ALTER TABLE player DROP average_rating');
    }
}
