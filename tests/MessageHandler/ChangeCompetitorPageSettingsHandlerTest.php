<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\ChangeCompetitorPageSettings;
use SpeedPuzzling\Web\Query\GetCompetitorPageSettings;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\QrTagMode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Competitor pages & QR settings (docs/features/competitions-management/toolkit/B-qr-competitor-page.md §2) - through
 * the real bus, read back through the query the hub uses.
 */
final class ChangeCompetitorPageSettingsHandlerTest extends KernelTestCase
{
    private const string EVENT = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;

    private MessageBusInterface $messageBus;
    private GetCompetitorPageSettings $getCompetitorPageSettings;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->getCompetitorPageSettings = self::getContainer()->get(GetCompetitorPageSettings::class);
    }

    public function testAnUntouchedCompetitionHasTheDefaults(): void
    {
        self::assertEquals(CompetitorPageSettings::defaults(), $this->getCompetitorPageSettings->of(self::EVENT));

        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT competitor_pages, competitor_pages_live_results, time_proposals, qr_tag_mode FROM competition WHERE id = :id',
            ['id' => self::EVENT],
        );
        self::assertSame(['competitor_pages' => false, 'competitor_pages_live_results' => false, 'time_proposals' => false, 'qr_tag_mode' => null], $row);
    }

    public function testSavesEverySetting(): void
    {
        $settings = new CompetitorPageSettings(
            competitorPages: true,
            liveResultsBeforePublishing: true,
            timeProposals: true,
            qrTagMode: QrTagMode::RoundEntry,
        );

        $this->messageBus->dispatch(new ChangeCompetitorPageSettings(self::EVENT, $settings));

        self::assertEquals($settings, $this->getCompetitorPageSettings->of(self::EVENT));
    }

    public function testSwitchingPagesOffSwitchesProposalsOff(): void
    {
        $this->messageBus->dispatch(new ChangeCompetitorPageSettings(self::EVENT, new CompetitorPageSettings(competitorPages: true, timeProposals: true)));
        $this->messageBus->dispatch(new ChangeCompetitorPageSettings(self::EVENT, new CompetitorPageSettings(competitorPages: false, timeProposals: true, qrTagMode: QrTagMode::Entrant)));

        $saved = $this->getCompetitorPageSettings->of(self::EVENT);
        self::assertFalse($saved->competitorPages);
        self::assertFalse($saved->timeProposals);
        self::assertSame(QrTagMode::Entrant, $saved->qrTagMode);
    }

    public function testBackToDefaults(): void
    {
        $this->messageBus->dispatch(new ChangeCompetitorPageSettings(self::EVENT, new CompetitorPageSettings(true, true, true, QrTagMode::Table)));
        $this->messageBus->dispatch(new ChangeCompetitorPageSettings(self::EVENT, CompetitorPageSettings::defaults()));

        self::assertEquals(CompetitorPageSettings::defaults(), $this->getCompetitorPageSettings->of(self::EVENT));
    }

    public function testAnUnknownCompetition(): void
    {
        $this->expectException(CompetitionNotFound::class);

        $this->messageBus->dispatch(new ChangeCompetitorPageSettings('018d0004-0000-0000-0000-00000000ffff', CompetitorPageSettings::defaults()));
    }
}
