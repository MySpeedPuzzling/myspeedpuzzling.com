<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written (no table changes): gen_uuid_v7(), a time-ordered UUID (version 7, RFC 9562) for the ids that SQL
 * creates itself.
 *
 * The insights recalculation writes its rows with set-based INSERT ... SELECT statements, so their ids cannot come
 * from PHP's Uuid::uuid7() and come from gen_random_uuid() - version 4, random. A random id lands on a random page of
 * the primary key index: on production (1-16 Nov 2026) the player_rating_snapshot upsert alone wrote ~3,500 full page
 * images and 13 MB of WAL per 15-minute run, a third of all WAL. With ids from this function the rows of one day sit
 * next to each other in that index. Postgres 16 has no uuidv7() of its own (18 does), hence this function.
 *
 * Only the function here. The statements switch to it in the release after this one: a function must be on
 * production before any code calls it, and the cron's one-off containers run a new image as soon as it is pulled -
 * before the new web container has booted and migrated.
 *
 * - 48 bits of Unix milliseconds, then version 7, the variant bits and 74 random bits (the random v4 it starts from).
 * - clock_timestamp(), not now(): the real time of every single call. now() is the start of the transaction, the same
 *   for every row of a long recalculation. It is the database's clock on purpose - the prefix orders ids, it is no
 *   domain time (Uuid::uuid7() reads the system clock as well); the app's clock stays the only source of stored times.
 * - VOLATILE (a new value per row) and PARALLEL SAFE, as gen_random_uuid() is - without it the planner would refuse a
 *   parallel plan to every statement that calls it.
 * - Its own name, not uuidv7(): on Postgres 18 pg_catalog.uuidv7() would silently shadow a function of that name in
 *   public. After that upgrade the callers can move to the built-in one and this function be dropped.
 *
 * Mirrored in tests/bootstrap.php (the test database is built without migrations); GenUuidV7FunctionTest keeps the
 * two definitions the same. Documented in .claude/rules/database.md.
 *
 * Safe across the blue-green deploy: creating a function locks no table and nothing calls it yet.
 */
final class Version20261116160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'gen_uuid_v7(): time-ordered UUIDs for the ids created in SQL (insights recalculation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE OR REPLACE FUNCTION gen_uuid_v7() RETURNS uuid
LANGUAGE sql VOLATILE PARALLEL SAFE
AS $$
    SELECT encode(
        set_bit(
            set_bit(
                overlay(
                    uuid_send(gen_random_uuid())
                    PLACING substring(int8send(floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3)
                    FROM 1 FOR 6
                ),
                52, 1
            ),
            53, 1
        ),
        'hex'
    )::uuid
$$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP FUNCTION IF EXISTS gen_uuid_v7()');
    }
}
