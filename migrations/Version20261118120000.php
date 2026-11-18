<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Participant linking L5 (docs/features/competitions-management/participant-linking.md §3.5): the name key of every
 * player, for the link suggestions' "Name and country" tier and the "≤ 3 players per name key" rule. Hand-written
 * (no-Docker mode) - Doctrine does not manage `custom_*` indexes (CustomIndexFilteringSchemaManagerFactory).
 *
 * NAME_KEY is ParticipantNameKey::sql('name') word for word (ParticipantNameKeySqlTest compares them): a query uses
 * the index only with exactly this expression.
 *
 * A plain CREATE INDEX (not CONCURRENTLY - migrations run in a transaction): it locks writes to `player` while it is
 * built, a few seconds on production during the deploy - a sign-up or profile edit in that moment waits.
 */
final class Version20261118120000 extends AbstractMigration
{
    public const string NAME_KEY = <<<'SQL'
btrim(regexp_replace(lower(immutable_unaccent(regexp_replace(regexp_replace(regexp_replace(name, '[\u00ad\u200b-\u200f\u202a-\u202e\u2060-\u2064\u2066-\u2069\ufeff]', '', 'g'), '[\u2010-\u2015\u2212]', '-', 'g'), '[''`\u00b4\u02b9\u02bc\u2018\u2019\u201b\u2032\uff07]+', '', 'g'))), '[[:space:]\u00a0\u1680\u2000-\u200a\u2028\u2029\u202f\u205f\u3000]+', ' ', 'g'), ' ')
SQL;

    public function getDescription(): string
    {
        return 'Participant linking L5: custom_player_name_key (the name key of player.name)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX custom_player_name_key ON player ((' . self::NAME_KEY . '))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX custom_player_name_key');
    }
}
