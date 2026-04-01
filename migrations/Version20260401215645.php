<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260401215645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oauth2_client_request (status VARCHAR(255) NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, rejection_reason TEXT DEFAULT NULL, client_identifier VARCHAR(255) DEFAULT NULL, credential_claim_token VARCHAR(255) DEFAULT NULL, credential_claim_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, credentials_claimed BOOLEAN NOT NULL, client_secret VARCHAR(255) DEFAULT NULL, id UUID NOT NULL, client_name VARCHAR(100) NOT NULL, client_description TEXT NOT NULL, purpose TEXT NOT NULL, application_type VARCHAR(255) NOT NULL, requested_scopes JSON NOT NULL, redirect_uris JSON NOT NULL, fair_use_policy_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, logo_path VARCHAR(255) DEFAULT NULL, reviewed_by_id UUID DEFAULT NULL, player_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_21926B8FFC6B21F1 ON oauth2_client_request (reviewed_by_id)');
        $this->addSql('CREATE INDEX idx_oauth2_client_request_player ON oauth2_client_request (player_id)');
        $this->addSql('CREATE INDEX idx_oauth2_client_request_status ON oauth2_client_request (status)');
        $this->addSql('ALTER TABLE oauth2_client_request ADD CONSTRAINT FK_21926B8FFC6B21F1 FOREIGN KEY (reviewed_by_id) REFERENCES player (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE oauth2_client_request ADD CONSTRAINT FK_21926B8F99E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth2_client_request DROP CONSTRAINT FK_21926B8FFC6B21F1');
        $this->addSql('ALTER TABLE oauth2_client_request DROP CONSTRAINT FK_21926B8F99E6F5DF');
        $this->addSql('DROP TABLE oauth2_client_request');
    }
}
