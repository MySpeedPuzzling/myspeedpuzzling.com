<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261027093158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sell/swap requests: listing_request + listing_request_item + listing_request_offer, notification target, player.listing_request_emails';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE listing_request (note VARCHAR(500) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, replied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id UUID NOT NULL, send_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, buyer_id UUID NOT NULL, seller_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_24A9F4D06C755722 ON listing_request (buyer_id)');
        $this->addSql('CREATE INDEX IDX_24A9F4D08DE820D9 ON listing_request (seller_id)');
        $this->addSql('CREATE INDEX listing_request_buyer_created ON listing_request (buyer_id, created_at)');
        $this->addSql('CREATE INDEX listing_request_seller_created ON listing_request (seller_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX listing_request_send_seller_unique ON listing_request (send_id, seller_id)');
        $this->addSql('CREATE TABLE listing_request_item (status VARCHAR(20) NOT NULL, answered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reserved_by_request BOOLEAN DEFAULT false NOT NULL, listing_type VARCHAR(255) NOT NULL, price DOUBLE PRECISION DEFAULT NULL, currency VARCHAR(50) DEFAULT NULL, condition VARCHAR(255) NOT NULL, id UUID NOT NULL, buyer_id UUID NOT NULL, seller_id UUID NOT NULL, puzzle_id UUID NOT NULL, request_id UUID NOT NULL, listing_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E58E2CE56C755722 ON listing_request_item (buyer_id)');
        $this->addSql('CREATE INDEX IDX_E58E2CE58DE820D9 ON listing_request_item (seller_id)');
        $this->addSql('CREATE INDEX IDX_E58E2CE5D9816812 ON listing_request_item (puzzle_id)');
        $this->addSql('CREATE INDEX listing_request_item_request ON listing_request_item (request_id)');
        $this->addSql('CREATE INDEX listing_request_item_listing ON listing_request_item (listing_id)');
        $this->addSql('CREATE INDEX listing_request_item_seller_puzzle ON listing_request_item (seller_id, puzzle_id)');
        $this->addSql('CREATE TABLE listing_request_offer (listing_type VARCHAR(255) NOT NULL, condition VARCHAR(255) NOT NULL, id UUID NOT NULL, puzzle_id UUID NOT NULL, request_id UUID NOT NULL, listing_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_34339A3D9816812 ON listing_request_offer (puzzle_id)');
        $this->addSql('CREATE INDEX IDX_34339A3D4619D1A ON listing_request_offer (listing_id)');
        $this->addSql('CREATE INDEX listing_request_offer_request ON listing_request_offer (request_id)');
        $this->addSql('ALTER TABLE listing_request ADD CONSTRAINT FK_24A9F4D06C755722 FOREIGN KEY (buyer_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request ADD CONSTRAINT FK_24A9F4D08DE820D9 FOREIGN KEY (seller_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request_item ADD CONSTRAINT FK_E58E2CE56C755722 FOREIGN KEY (buyer_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request_item ADD CONSTRAINT FK_E58E2CE58DE820D9 FOREIGN KEY (seller_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request_item ADD CONSTRAINT FK_E58E2CE5D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request_item ADD CONSTRAINT FK_E58E2CE5427EB8A5 FOREIGN KEY (request_id) REFERENCES listing_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request_item ADD CONSTRAINT FK_E58E2CE5D4619D1A FOREIGN KEY (listing_id) REFERENCES sell_swap_list_item (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request_offer ADD CONSTRAINT FK_34339A3D9816812 FOREIGN KEY (puzzle_id) REFERENCES puzzle (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request_offer ADD CONSTRAINT FK_34339A3427EB8A5 FOREIGN KEY (request_id) REFERENCES listing_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listing_request_offer ADD CONSTRAINT FK_34339A3D4619D1A FOREIGN KEY (listing_id) REFERENCES sell_swap_list_item (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD target_listing_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAF5730683 FOREIGN KEY (target_listing_request_id) REFERENCES listing_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CAF5730683 ON notification (target_listing_request_id)');
        $this->addSql('ALTER TABLE player ADD listing_request_emails BOOLEAN DEFAULT true NOT NULL');

        // One open ask (waiting or yes) per buyer and listing - AskForListingsHandler skips a second one, the index stops
        // a race (docs/features/marketplace/12-listing-requests.md)
        $this->addSql("CREATE UNIQUE INDEX custom_listing_request_item_one_open ON listing_request_item (buyer_id, listing_id) WHERE status IN ('waiting', 'yes')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX custom_listing_request_item_one_open');
        $this->addSql('ALTER TABLE listing_request DROP CONSTRAINT FK_24A9F4D06C755722');
        $this->addSql('ALTER TABLE listing_request DROP CONSTRAINT FK_24A9F4D08DE820D9');
        $this->addSql('ALTER TABLE listing_request_item DROP CONSTRAINT FK_E58E2CE56C755722');
        $this->addSql('ALTER TABLE listing_request_item DROP CONSTRAINT FK_E58E2CE58DE820D9');
        $this->addSql('ALTER TABLE listing_request_item DROP CONSTRAINT FK_E58E2CE5D9816812');
        $this->addSql('ALTER TABLE listing_request_item DROP CONSTRAINT FK_E58E2CE5427EB8A5');
        $this->addSql('ALTER TABLE listing_request_item DROP CONSTRAINT FK_E58E2CE5D4619D1A');
        $this->addSql('ALTER TABLE listing_request_offer DROP CONSTRAINT FK_34339A3D9816812');
        $this->addSql('ALTER TABLE listing_request_offer DROP CONSTRAINT FK_34339A3427EB8A5');
        $this->addSql('ALTER TABLE listing_request_offer DROP CONSTRAINT FK_34339A3D4619D1A');
        $this->addSql('DROP TABLE listing_request');
        $this->addSql('DROP TABLE listing_request_item');
        $this->addSql('DROP TABLE listing_request_offer');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAF5730683');
        $this->addSql('DROP INDEX IDX_BF5476CAF5730683');
        $this->addSql('ALTER TABLE notification DROP target_listing_request_id');
        $this->addSql('ALTER TABLE player DROP listing_request_emails');
    }
}
