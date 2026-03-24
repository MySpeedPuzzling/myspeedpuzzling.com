<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324082438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop unique constraint on feature_request_vote to allow repeated votes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_9dd2140d5cc9550debb4b8ad');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_9dd2140d5cc9550debb4b8ad ON feature_request_vote (feature_request_id, voter_id)');
    }
}
