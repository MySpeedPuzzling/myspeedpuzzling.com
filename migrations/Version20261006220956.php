<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261006220956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE api_caller_day (id UUID NOT NULL, day DATE NOT NULL, caller_key VARCHAR(255) NOT NULL, oauth2_client_identifier VARCHAR(255) DEFAULT NULL, peak_requests_per_minute INT NOT NULL, last_request_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, player_id UUID DEFAULT NULL, personal_access_token_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CC1E6FA699E6F5DF ON api_caller_day (player_id)');
        $this->addSql('CREATE INDEX IDX_CC1E6FA6E5A02990 ON api_caller_day (day)');
        $this->addSql('CREATE INDEX IDX_CC1E6FA699E6F5DFE5A02990 ON api_caller_day (player_id, day)');
        $this->addSql('CREATE INDEX IDX_CC1E6FA6F1A0E297 ON api_caller_day (personal_access_token_id)');
        $this->addSql('CREATE INDEX IDX_CC1E6FA62170E267E5A02990 ON api_caller_day (oauth2_client_identifier, day)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CC1E6FA6E5A029906484918E ON api_caller_day (day, caller_key)');
        $this->addSql('CREATE TABLE api_usage_day (id UUID NOT NULL, day DATE NOT NULL, caller_key VARCHAR(255) NOT NULL, oauth2_client_identifier VARCHAR(255) DEFAULT NULL, operation VARCHAR(255) NOT NULL, status_class VARCHAR(3) NOT NULL, requests INT NOT NULL, duration_ms_total BIGINT NOT NULL, player_id UUID DEFAULT NULL, personal_access_token_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E35BCEC499E6F5DF ON api_usage_day (player_id)');
        $this->addSql('CREATE INDEX IDX_E35BCEC4E5A02990 ON api_usage_day (day)');
        $this->addSql('CREATE INDEX IDX_E35BCEC499E6F5DFE5A02990 ON api_usage_day (player_id, day)');
        $this->addSql('CREATE INDEX IDX_E35BCEC4F1A0E297 ON api_usage_day (personal_access_token_id)');
        $this->addSql('CREATE INDEX IDX_E35BCEC42170E267E5A02990 ON api_usage_day (oauth2_client_identifier, day)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E35BCEC4E5A029906484918E1981A66D1A11486F ON api_usage_day (day, caller_key, operation, status_class)');
        $this->addSql('ALTER TABLE api_caller_day ADD CONSTRAINT FK_CC1E6FA699E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_caller_day ADD CONSTRAINT FK_CC1E6FA6F1A0E297 FOREIGN KEY (personal_access_token_id) REFERENCES personal_access_token (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_usage_day ADD CONSTRAINT FK_E35BCEC499E6F5DF FOREIGN KEY (player_id) REFERENCES player (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE api_usage_day ADD CONSTRAINT FK_E35BCEC4F1A0E297 FOREIGN KEY (personal_access_token_id) REFERENCES personal_access_token (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_caller_day DROP CONSTRAINT FK_CC1E6FA699E6F5DF');
        $this->addSql('ALTER TABLE api_caller_day DROP CONSTRAINT FK_CC1E6FA6F1A0E297');
        $this->addSql('ALTER TABLE api_usage_day DROP CONSTRAINT FK_E35BCEC499E6F5DF');
        $this->addSql('ALTER TABLE api_usage_day DROP CONSTRAINT FK_E35BCEC4F1A0E297');
        $this->addSql('DROP TABLE api_caller_day');
        $this->addSql('DROP TABLE api_usage_day');
    }
}
