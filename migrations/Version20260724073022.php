<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724073022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Native auth (issue #147): user_account table with case-insensitive unique email';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_account (password VARCHAR(255) DEFAULT NULL, email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, last_login_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, legacy_auth0 BOOLEAN DEFAULT false NOT NULL, id UUID NOT NULL, user_id VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, registered_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_253B48AEA76ED395 ON user_account (user_id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_253B48AEE7927C74 ON user_account (email)');
        // Custom expression index (Doctrine-ignored via custom_ prefix, mirrored in tests/bootstrap.php):
        // guards against case-variant duplicate emails even if a write path skips canonicalization
        $this->addSql('CREATE UNIQUE INDEX custom_user_account_email_lower ON user_account (lower(email))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE user_account');
    }
}
