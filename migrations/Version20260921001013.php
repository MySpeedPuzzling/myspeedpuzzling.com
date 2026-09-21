<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260921001013 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pairs & teams: guest_link_request ("this guest of mine is you") + notification.target_guest_link_request_id';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE guest_link_request (resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, accepted BOOLEAN DEFAULT NULL, id UUID NOT NULL, guest_key VARCHAR(255) NOT NULL, guest_name VARCHAR(255) NOT NULL, requested_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, requester_id UUID NOT NULL, target_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_45C002DDED442CF4 ON guest_link_request (requester_id)');
        $this->addSql('CREATE INDEX IDX_45C002DD158E0B66 ON guest_link_request (target_id)');
        $this->addSql('CREATE INDEX IDX_45C002DDED442CF4846E505A ON guest_link_request (requester_id, guest_key)');
        $this->addSql('ALTER TABLE guest_link_request ADD CONSTRAINT FK_45C002DDED442CF4 FOREIGN KEY (requester_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE guest_link_request ADD CONSTRAINT FK_45C002DD158E0B66 FOREIGN KEY (target_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD target_guest_link_request_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA5F704F2 FOREIGN KEY (target_guest_link_request_id) REFERENCES guest_link_request (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA5F704F2 ON notification (target_guest_link_request_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE guest_link_request DROP CONSTRAINT FK_45C002DDED442CF4');
        $this->addSql('ALTER TABLE guest_link_request DROP CONSTRAINT FK_45C002DD158E0B66');
        $this->addSql('DROP TABLE guest_link_request');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA5F704F2');
        $this->addSql('DROP INDEX IDX_BF5476CA5F704F2');
        $this->addSql('ALTER TABLE notification DROP target_guest_link_request_id');
    }
}
