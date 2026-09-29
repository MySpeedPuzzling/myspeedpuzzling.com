<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929125011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Social login: one identity per provider per account (unique user_account_id + provider on oauth_identity)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4AE96AD83C0C995692C4739C ON oauth_identity (user_account_id, provider)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_4AE96AD83C0C995692C4739C');
    }
}
