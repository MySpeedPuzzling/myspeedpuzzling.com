<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\QrTagMode;

final class CompetitorPageSettingsTest extends TestCase
{
    public function testTheDefaultsAreTheBehaviourBeforeTheToolkit(): void
    {
        $settings = CompetitorPageSettings::defaults();

        self::assertFalse($settings->competitorPages);
        self::assertFalse($settings->liveResultsBeforePublishing);
        self::assertFalse($settings->timeProposals);
        self::assertNull($settings->qrTagMode);
    }

    public function testPagesOffSwitchTimeProposalsOff(): void
    {
        $settings = new CompetitorPageSettings(
            competitorPages: false,
            liveResultsBeforePublishing: true,
            timeProposals: true,
            qrTagMode: QrTagMode::Table,
        );

        self::assertFalse($settings->timeProposals);
        // Only the proposals depend on the pages
        self::assertTrue($settings->liveResultsBeforePublishing);
        self::assertSame(QrTagMode::Table, $settings->qrTagMode);
    }

    public function testPagesOnKeepTimeProposals(): void
    {
        $settings = new CompetitorPageSettings(competitorPages: true, timeProposals: true);

        self::assertTrue($settings->timeProposals);
    }
}
