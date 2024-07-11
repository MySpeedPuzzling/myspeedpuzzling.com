<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20240711210112 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE badge ALTER earned_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN badge.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN badge.player_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN badge.earned_at IS \'\'');
        $this->addSql('ALTER TABLE competition ALTER date TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN competition.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN competition.date IS \'\'');
        $this->addSql('ALTER TABLE competition_round ALTER starts_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN competition_round.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN competition_round.starts_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN competition_round.competition_id IS \'\'');
        $this->addSql('ALTER TABLE manufacturer ALTER added_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN manufacturer.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN manufacturer.added_by_user_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN manufacturer.added_at IS \'\'');
        $this->addSql('ALTER TABLE notification ALTER read_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE notification ALTER notified_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN notification.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN notification.player_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN notification.target_solving_time_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN notification.read_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN notification.notified_at IS \'\'');
        $this->addSql('ALTER TABLE player ALTER registered_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN player.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN player.registered_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.player_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.puzzle_id IS \'\'');
        $this->addSql('ALTER TABLE puzzle ALTER added_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN puzzle.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle.manufacturer_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle.added_by_user_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle.added_at IS \'\'');
        $this->addSql('ALTER TABLE puzzle_solving_time ALTER tracked_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE puzzle_solving_time ALTER team TYPE JSON');
        $this->addSql('ALTER TABLE puzzle_solving_time ALTER finished_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.player_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.puzzle_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.tracked_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.team IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.finished_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.competition_round_id IS \'\'');
        $this->addSql('ALTER TABLE stopwatch ALTER laps TYPE JSON');
        $this->addSql('COMMENT ON COLUMN stopwatch.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN stopwatch.player_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN stopwatch.puzzle_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN stopwatch.laps IS \'\'');
        $this->addSql('COMMENT ON COLUMN tag.id IS \'\'');
        $this->addSql('COMMENT ON COLUMN tag_puzzle.tag_id IS \'\'');
        $this->addSql('COMMENT ON COLUMN tag_puzzle.puzzle_id IS \'\'');
        $this->addSql('ALTER TABLE messenger_messages ALTER id DROP DEFAULT');
        $this->addSql('ALTER TABLE messenger_messages ALTER id ADD GENERATED BY DEFAULT AS IDENTITY');
        $this->addSql('ALTER TABLE messenger_messages ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE messenger_messages ALTER available_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE messenger_messages ALTER delivered_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN messenger_messages.created_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.available_at IS \'\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.delivered_at IS \'\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE messenger_messages ALTER id SET DEFAULT messenger_messages_id_seq');
        $this->addSql('ALTER TABLE messenger_messages ALTER id DROP IDENTITY');
        $this->addSql('ALTER TABLE messenger_messages ALTER created_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE messenger_messages ALTER available_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE messenger_messages ALTER delivered_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN messenger_messages.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.available_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN messenger_messages.delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN tag_puzzle.tag_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN tag_puzzle.puzzle_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN tag.id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE puzzle_solving_time ALTER tracked_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE puzzle_solving_time ALTER team TYPE JSON');
        $this->addSql('ALTER TABLE puzzle_solving_time ALTER finished_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.tracked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.team IS \'(DC2Type:puzzlers_group)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.finished_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.player_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.puzzle_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle_solving_time.competition_round_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE player ALTER registered_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN player.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN player.registered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE puzzle ALTER added_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN puzzle.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle.added_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN puzzle.manufacturer_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN puzzle.added_by_user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE competition_round ALTER starts_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN competition_round.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN competition_round.starts_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN competition_round.competition_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE competition ALTER date TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN competition.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN competition.date IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE manufacturer ALTER added_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN manufacturer.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN manufacturer.added_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN manufacturer.added_by_user_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE stopwatch ALTER laps TYPE JSON');
        $this->addSql('COMMENT ON COLUMN stopwatch.laps IS \'(DC2Type:laps[])\'');
        $this->addSql('COMMENT ON COLUMN stopwatch.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN stopwatch.player_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN stopwatch.puzzle_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE badge ALTER earned_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN badge.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN badge.earned_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN badge.player_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE notification ALTER read_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE notification ALTER notified_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN notification.read_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN notification.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN notification.notified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN notification.player_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN notification.target_solving_time_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.player_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN player_puzzle_collection.puzzle_id IS \'(DC2Type:uuid)\'');
    }
}
