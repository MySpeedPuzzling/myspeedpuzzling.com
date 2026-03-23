<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260323221513 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create feature request tables (feature_request, feature_request_vote, feature_request_comment, feature_request_comment_report)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feature_request (vote_count INT NOT NULL, id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, author_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A801496F675F31B ON feature_request (author_id)');
        $this->addSql('CREATE INDEX IDX_A801496D4B026A98B8E8428 ON feature_request (vote_count, created_at)');
        $this->addSql('CREATE TABLE feature_request_comment (id UUID NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, feature_request_id UUID NOT NULL, author_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CE42BAC35CC9550D ON feature_request_comment (feature_request_id)');
        $this->addSql('CREATE INDEX IDX_CE42BAC3F675F31B ON feature_request_comment (author_id)');
        $this->addSql('CREATE INDEX IDX_CE42BAC35CC9550D8B8E8428 ON feature_request_comment (feature_request_id, created_at)');
        $this->addSql('CREATE TABLE feature_request_comment_report (resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, admin_note TEXT DEFAULT NULL, id UUID NOT NULL, reason TEXT NOT NULL, status VARCHAR(255) NOT NULL, reported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_by_id UUID DEFAULT NULL, comment_id UUID NOT NULL, reporter_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_CF19B71E6713A32B ON feature_request_comment_report (resolved_by_id)');
        $this->addSql('CREATE INDEX IDX_CF19B71EF8697D13 ON feature_request_comment_report (comment_id)');
        $this->addSql('CREATE INDEX IDX_CF19B71EE1CFE6F5 ON feature_request_comment_report (reporter_id)');
        $this->addSql('CREATE INDEX IDX_CF19B71E7B00651C ON feature_request_comment_report (status)');
        $this->addSql('CREATE INDEX IDX_CF19B71E41D374DA ON feature_request_comment_report (reported_at)');
        $this->addSql('CREATE TABLE feature_request_vote (id UUID NOT NULL, voted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, feature_request_id UUID NOT NULL, voter_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9DD2140D5CC9550D ON feature_request_vote (feature_request_id)');
        $this->addSql('CREATE INDEX IDX_9DD2140DEBB4B8AD ON feature_request_vote (voter_id)');
        $this->addSql('CREATE INDEX IDX_9DD2140DEBB4B8AD4BA82A82 ON feature_request_vote (voter_id, voted_at)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_9DD2140D5CC9550DEBB4B8AD ON feature_request_vote (feature_request_id, voter_id)');
        $this->addSql('ALTER TABLE feature_request ADD CONSTRAINT FK_A801496F675F31B FOREIGN KEY (author_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature_request_comment ADD CONSTRAINT FK_CE42BAC35CC9550D FOREIGN KEY (feature_request_id) REFERENCES feature_request (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature_request_comment ADD CONSTRAINT FK_CE42BAC3F675F31B FOREIGN KEY (author_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature_request_comment_report ADD CONSTRAINT FK_CF19B71E6713A32B FOREIGN KEY (resolved_by_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature_request_comment_report ADD CONSTRAINT FK_CF19B71EF8697D13 FOREIGN KEY (comment_id) REFERENCES feature_request_comment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature_request_comment_report ADD CONSTRAINT FK_CF19B71EE1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES player (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature_request_vote ADD CONSTRAINT FK_9DD2140D5CC9550D FOREIGN KEY (feature_request_id) REFERENCES feature_request (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE feature_request_vote ADD CONSTRAINT FK_9DD2140DEBB4B8AD FOREIGN KEY (voter_id) REFERENCES player (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feature_request DROP CONSTRAINT FK_A801496F675F31B');
        $this->addSql('ALTER TABLE feature_request_comment DROP CONSTRAINT FK_CE42BAC35CC9550D');
        $this->addSql('ALTER TABLE feature_request_comment DROP CONSTRAINT FK_CE42BAC3F675F31B');
        $this->addSql('ALTER TABLE feature_request_comment_report DROP CONSTRAINT FK_CF19B71E6713A32B');
        $this->addSql('ALTER TABLE feature_request_comment_report DROP CONSTRAINT FK_CF19B71EF8697D13');
        $this->addSql('ALTER TABLE feature_request_comment_report DROP CONSTRAINT FK_CF19B71EE1CFE6F5');
        $this->addSql('ALTER TABLE feature_request_vote DROP CONSTRAINT FK_9DD2140D5CC9550D');
        $this->addSql('ALTER TABLE feature_request_vote DROP CONSTRAINT FK_9DD2140DEBB4B8AD');
        $this->addSql('DROP TABLE feature_request');
        $this->addSql('DROP TABLE feature_request_comment');
        $this->addSql('DROP TABLE feature_request_comment_report');
        $this->addSql('DROP TABLE feature_request_vote');
    }
}
