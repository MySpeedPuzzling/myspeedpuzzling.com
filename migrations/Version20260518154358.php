<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260518154358 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message ADD sender_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE conversation ADD initiator_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE conversation ADD recipient_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE conversation ALTER initiator_id DROP NOT NULL');
        $this->addSql('ALTER TABLE conversation ALTER recipient_id DROP NOT NULL');
        $this->addSql('ALTER TABLE conversation_report ALTER reporter_id DROP NOT NULL');
        $this->addSql('ALTER TABLE feature_request ADD author_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE feature_request ALTER author_id DROP NOT NULL');
        $this->addSql('ALTER TABLE feature_request_comment ADD author_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE feature_request_comment ALTER author_id DROP NOT NULL');
        $this->addSql('ALTER TABLE feature_request_comment_report ALTER reporter_id DROP NOT NULL');
        $this->addSql('ALTER TABLE feature_request_vote ALTER voter_id DROP NOT NULL');
        $this->addSql('ALTER TABLE moderation_action ALTER target_player_id DROP NOT NULL');
        $this->addSql('ALTER TABLE sold_swapped_item ADD seller_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE sold_swapped_item ALTER seller_id DROP NOT NULL');
        $this->addSql('ALTER TABLE transaction_rating ADD reviewer_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_rating ADD reviewed_player_name VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_rating ALTER reviewer_id DROP NOT NULL');
        $this->addSql('ALTER TABLE transaction_rating ALTER reviewed_player_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE chat_message DROP sender_name');
        $this->addSql('ALTER TABLE conversation DROP initiator_name');
        $this->addSql('ALTER TABLE conversation DROP recipient_name');
        $this->addSql('ALTER TABLE conversation ALTER initiator_id SET NOT NULL');
        $this->addSql('ALTER TABLE conversation ALTER recipient_id SET NOT NULL');
        $this->addSql('ALTER TABLE conversation_report ALTER reporter_id SET NOT NULL');
        $this->addSql('ALTER TABLE feature_request DROP author_name');
        $this->addSql('ALTER TABLE feature_request ALTER author_id SET NOT NULL');
        $this->addSql('ALTER TABLE feature_request_comment DROP author_name');
        $this->addSql('ALTER TABLE feature_request_comment ALTER author_id SET NOT NULL');
        $this->addSql('ALTER TABLE feature_request_comment_report ALTER reporter_id SET NOT NULL');
        $this->addSql('ALTER TABLE feature_request_vote ALTER voter_id SET NOT NULL');
        $this->addSql('ALTER TABLE moderation_action ALTER target_player_id SET NOT NULL');
        $this->addSql('ALTER TABLE sold_swapped_item DROP seller_name');
        $this->addSql('ALTER TABLE sold_swapped_item ALTER seller_id SET NOT NULL');
        $this->addSql('ALTER TABLE transaction_rating DROP reviewer_name');
        $this->addSql('ALTER TABLE transaction_rating DROP reviewed_player_name');
        $this->addSql('ALTER TABLE transaction_rating ALTER reviewer_id SET NOT NULL');
        $this->addSql('ALTER TABLE transaction_rating ALTER reviewed_player_id SET NOT NULL');
    }
}
