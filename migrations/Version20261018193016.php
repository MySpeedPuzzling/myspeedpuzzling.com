<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Ramsey\Uuid\Uuid;

/**
 * Change requests carry who filed them and the image pipeline's metadata (docs/features/insights/implementation-plan.md
 * §1.3/§1.4), plus an index for a change request's decisions.
 *
 * Backfill of `source` (by hand): nothing stored tells the two apart, so the rule is the one way the internal API files -
 * in the name of INTERNAL_API_REVIEWER_PLAYER_ID, only since the endpoint shipped (2026-10-04), never with a photo.
 * Such rows become `internal_api`, every other row `player`. Without the variable (dev, tests) every row is `player`.
 * A web proposal filed through the reviewer player's own account since then would read as `internal_api` - none is
 * expected (the reviewer player is an admin account, and admins edit a puzzle directly).
 */
final class Version20261018193016 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'puzzle_change_request: source, tags, confidence, model_version, source_image_fingerprint, original_archive_path (+ source backfill); index on puzzle_moderation_decision.change_request_id';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_change_request ADD original_archive_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD source VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD tags JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD confidence DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD model_version VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE puzzle_change_request ADD source_image_fingerprint VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_DA11B82D96829572 ON puzzle_moderation_decision (change_request_id)');

        $reviewerPlayerId = self::reviewerPlayerId();

        if ($reviewerPlayerId !== null) {
            $this->addSql(
                "UPDATE puzzle_change_request SET source = 'internal_api' WHERE source IS NULL AND reporter_id = :reviewer AND submitted_at >= '2026-10-04' AND proposed_image IS NULL",
                ['reviewer' => $reviewerPlayerId],
            );
        }

        $this->addSql("UPDATE puzzle_change_request SET source = 'player' WHERE source IS NULL");
    }

    private static function reviewerPlayerId(): null|string
    {
        $value = $_SERVER['INTERNAL_API_REVIEWER_PLAYER_ID'] ?? $_ENV['INTERNAL_API_REVIEWER_PLAYER_ID'] ?? getenv('INTERNAL_API_REVIEWER_PLAYER_ID');

        return is_string($value) && Uuid::isValid(trim($value)) ? strtolower(trim($value)) : null;
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE puzzle_change_request DROP original_archive_path');
        $this->addSql('ALTER TABLE puzzle_change_request DROP source');
        $this->addSql('ALTER TABLE puzzle_change_request DROP tags');
        $this->addSql('ALTER TABLE puzzle_change_request DROP confidence');
        $this->addSql('ALTER TABLE puzzle_change_request DROP model_version');
        $this->addSql('ALTER TABLE puzzle_change_request DROP source_image_fingerprint');
        $this->addSql('DROP INDEX IDX_DA11B82D96829572');
    }
}
