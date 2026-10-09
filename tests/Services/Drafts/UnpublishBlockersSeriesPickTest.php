<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Drafts;

use SpeedPuzzling\Web\Exceptions\CannotUnpublish;
use SpeedPuzzling\Web\Message\UnpublishCompetitionSeries;
use SpeedPuzzling\Web\Query\GetOrganizedEvents;
use SpeedPuzzling\Web\Services\Drafts\UnpublishBlockers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\UnpublishBlocker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A series' unpublish blockers count its series picks (docs/features/events-page/high-frequency-series.md P20): a
 * series-level time - a series pick no edition holds, a normal and permanent result of the series - keeps the series
 * public like a time linked to one of its editions. A competition's blockers are unchanged: every time linked to it
 * counts, automatic links to an edition included.
 */
final class UnpublishBlockersSeriesPickTest extends KernelTestCase
{
    private const string PLAYER = PlayerFixture::PLAYER_REGULAR_USER_ID;

    private SeriesEditionScenario $scenario;
    private UnpublishBlockers $blockers;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scenario = new SeriesEditionScenario(self::getContainer());
        $this->blockers = self::getContainer()->get(UnpublishBlockers::class);
    }

    public function testASeriesLevelTimeBlocksTheSeries(): void
    {
        $seriesId = $this->scenario->series();
        $otherSeriesId = $this->scenario->series('Moonlight Sprint Nights');
        self::assertTrue($this->blockers->forSeries($seriesId)->allowed());

        // No edition at all: the time stays series-level
        $timeId = $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-09-15', seriesId: $seriesId);
        self::assertNull($this->scenario->link($timeId)['competition_id']);

        $check = $this->blockers->forSeries($seriesId);
        self::assertSame(1, $check->solvingTimes);
        self::assertSame([UnpublishBlocker::SolvingTimes], $check->blockers());
        self::assertTrue($this->blockers->forSeries($otherSeriesId)->allowed(), 'Another series\' pick blocks nothing here');

        // "You organize" offers no Unpublish either
        $organized = self::getContainer()->get(GetOrganizedEvents::class)->byIds([], [$seriesId, $otherSeriesId]);
        $blockers = [];

        foreach ($organized as $item) {
            $blockers[$item->id] = $item->unpublishBlockers;
        }

        self::assertSame([UnpublishBlocker::SolvingTimes], $blockers[$seriesId]);
        self::assertSame([], $blockers[$otherSeriesId]);
    }

    public function testATimeMatchedToAnEditionCountsOnceForTheSeriesAndForTheEdition(): void
    {
        $seriesId = $this->scenario->series();
        $puzzleId = $this->scenario->puzzle();
        $editionId = $this->scenario->edition($seriesId, 'Jam No. 154', '2026-09-16');
        $emptyEditionId = $this->scenario->edition($seriesId, 'Jam No. 155', '2026-09-18');
        $this->scenario->round($editionId, RoundCategory::Solo, '2026-09-16 19:00', puzzleIds: [$puzzleId]);

        $timeId = $this->scenario->addTime(self::PLAYER, $puzzleId, '2026-09-16', seriesId: $seriesId);
        self::assertSame($editionId, $this->scenario->link($timeId)['competition_id']);

        self::assertSame(1, $this->blockers->forSeries($seriesId)->solvingTimes, 'Linked to the series and to its edition - one time');
        self::assertSame(1, $this->blockers->forCompetition($editionId)->solvingTimes, 'An automatic link counts for its edition (unchanged)');
        self::assertTrue($this->blockers->forCompetition($emptyEditionId)->allowed());

        // "You organize": the edition's count, the series-level times on top - none here
        $organized = self::getContainer()->get(GetOrganizedEvents::class)->byIds([], [$seriesId]);
        self::assertSame([UnpublishBlocker::SolvingTimes], $organized[0]->unpublishBlockers);
    }

    public function testTheSeriesCannotGoBackToDraftWithASeriesLevelTime(): void
    {
        $seriesId = $this->scenario->series();
        $this->scenario->addTime(self::PLAYER, $this->scenario->puzzle(), '2026-09-15', seriesId: $seriesId);

        $this->expectException(CannotUnpublish::class);

        $this->scenario->dispatch(new UnpublishCompetitionSeries($seriesId));
    }
}
