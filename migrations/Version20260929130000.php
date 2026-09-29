<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written data migration, explicitly requested by the product owner
 * (2026-09-29, social login hardening) - no schema change, so nothing for
 * doctrine:migrations:diff to generate.
 *
 * Social login's rule 2 (auto-link a provider identity on an email match) now
 * requires the EXISTING account's address to be verified. Every account that
 * exists today is treated as verified: they came through the Auth0 import or
 * native sign-up, have been in use, and email verification never gated
 * anything - leaving them NULL would push every current player off the
 * auto-link path to "sign in first, then connect". From now on only new
 * sign-ups and changed addresses start unverified.
 *
 * Existing non-null values are kept (COALESCE-free: the WHERE skips them).
 */
final class Version20260929130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark every existing user_account email as verified (social login rule-2 hardening)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE user_account SET email_verified_at = NOW() WHERE email_verified_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        // Irreversible on purpose: which rows were NULL before is not recorded,
        // and un-verifying real accounts would be worse than keeping them
    }
}
