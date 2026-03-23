<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323151359 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add granted_until and renewed_billing_period_end columns to membership';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership ADD granted_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD renewed_billing_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Migrate existing free memberships: move ends_at to granted_until
        // for memberships without a Stripe subscription (manually granted)
        $this->addSql('UPDATE membership SET granted_until = ends_at, ends_at = NULL WHERE stripe_subscription_id IS NULL AND ends_at IS NOT NULL');

        // Initialize renewed_billing_period_end for active subscriptions
        // so existing renewals don't re-trigger the event
        $this->addSql('UPDATE membership SET renewed_billing_period_end = billing_period_ends_at WHERE stripe_subscription_id IS NOT NULL AND billing_period_ends_at IS NOT NULL AND ends_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        // Restore free memberships: move granted_until back to ends_at
        $this->addSql('UPDATE membership SET ends_at = granted_until WHERE stripe_subscription_id IS NULL AND granted_until IS NOT NULL AND ends_at IS NULL');

        $this->addSql('ALTER TABLE membership DROP granted_until');
        $this->addSql('ALTER TABLE membership DROP renewed_billing_period_end');
    }
}
