<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetEventsViewerData;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\FollowTarget;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEventsViewerDataTest extends KernelTestCase
{
    public function testRegularPlayer(): void
    {
        self::bootKernel();
        $viewer = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // Going: WJPC 2024 (CompetitionParticipantFixture)
        self::assertTrue($viewer->isGoing(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertFalse($viewer->isGoing(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN));

        self::assertTrue($viewer->follows(FollowTarget::series(EventsPageFixture::SERIES_HARBOR_NIGHTS)));
        self::assertTrue($viewer->follows(FollowTarget::competition(EventsPageFixture::COMPETITION_MEADOW_TBA)));
        self::assertFalse($viewer->follows(FollowTarget::competition(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN)));
        self::assertSame([EventsPageFixture::SERIES_HARBOR_NIGHTS], $viewer->followedSeriesIds());
        self::assertSame([EventsPageFixture::COMPETITION_MEADOW_TBA], $viewer->followedCompetitionIds());

        // Organised: the unapproved event and Euro Jigsaw Jam (maintainer + creator), Garden Swap (created, rejected)
        $organized = $viewer->organizedCompetitionIds();
        sort($organized);
        $expected = [
            CompetitionFixture::COMPETITION_UNAPPROVED,
            CompetitionFixture::COMPETITION_RECURRING_ONLINE,
            EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED,
        ];
        sort($expected);

        self::assertSame($expected, $organized);
        self::assertSame([], $viewer->organizedSeriesIds());
        self::assertSame(3, $viewer->organizedCount());
    }

    /**
     * An edition is under its series when the viewer organises the series too - one item, not two
     */
    public function testEditionsOfAnOrganisedSeriesAreNotCountedTwice(): void
    {
        self::bootKernel();
        $viewer = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_ADMIN);

        self::assertContains(CompetitionSeriesFixture::SERIES_EJJ, $viewer->organizedSeriesIds());
        self::assertContains(EventsPageFixture::SERIES_HARBOR_NIGHTS, $viewer->organizedSeriesIds());
        self::assertContains(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, $viewer->organizedCompetitionIds());
        self::assertNotContains(EventsPageFixture::EDITION_HARBOR_1, $viewer->organizedCompetitionIds());
        self::assertSame(count($viewer->organizedCompetitionIds()) + count($viewer->organizedSeriesIds()), $viewer->organizedCount());
    }

    public function testAPlayerWithNothing(): void
    {
        self::bootKernel();
        $viewer = self::getContainer()->get(GetEventsViewerData::class)->forPlayer(PlayerFixture::PLAYER_PRIVATE);

        self::assertSame(0, $viewer->organizedCount());
        self::assertSame([], $viewer->followedSeriesIds());
    }
}
