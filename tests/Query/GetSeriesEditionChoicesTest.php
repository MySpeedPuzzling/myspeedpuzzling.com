<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetSeriesEditionChoices;
use SpeedPuzzling\Web\Results\SeriesEditionChoice;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The editions a player picks explicitly (docs/features/events-page/high-frequency-series.md "S1 - typing finds
 * editions" and "The short list"): public ones only, revealed round puzzles only.
 */
final class GetSeriesEditionChoicesTest extends KernelTestCase
{
    private GetSeriesEditionChoices $query;
    private Connection $database;
    private SeriesEditionScenario $scenario;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetSeriesEditionChoices::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->scenario = new SeriesEditionScenario(self::getContainer());
        $this->today = self::getContainer()->get(ClockInterface::class)->now();
    }

    public function testTypingFindsEditionsByEveryWordOfTheirSeriesNameOrShortcut(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $no153 = $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $no154 = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $may = $this->scenario->edition($seriesId, '#21 - May 2026', $this->day(-40));
        $this->database->executeStatement('UPDATE competition_series SET shortcut = :shortcut WHERE id = :id', ['shortcut' => 'LWJX', 'id' => $seriesId]);

        self::assertSame([$no154, $no153], $this->ids($this->query->search('No. 15')));
        self::assertSame([$no154], $this->ids($this->query->search('154')));
        self::assertSame([$no154], $this->ids($this->query->search('lantern 154')));
        // Accents and case ignored
        self::assertSame([$no154], $this->ids($this->query->search('JÁM 154')));
        self::assertSame([$no153], $this->ids($this->query->search('lwjx 153')));
        self::assertSame([$may], $this->ids($this->query->search('#21 - May')));
        // Every word must match somewhere
        self::assertSame([], $this->ids($this->query->search('lantern 999')));

        $choice = $this->query->search('154')[0];
        self::assertSame('Jam No. 154', $choice->name);
        self::assertSame($seriesId, $choice->seriesId);
        self::assertSame('Lantern Weekly Jam', $choice->seriesName);
        self::assertSame($this->day(-2), $choice->dayFrom?->format('Y-m-d'));
    }

    public function testTypingNeedsTwoCharacters(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));

        self::assertSame([], $this->query->search('J'));
        self::assertSame([], $this->query->search('  '));
    }

    public function testTypedResultsAreNearestToTodayFirstUndatedLast(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $old = $this->scenario->edition($seriesId, 'Jam No. 140', $this->day(-30));
        $undated = $this->scenario->edition($seriesId, 'Jam Special', null);
        $recent = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $next = $this->scenario->edition($seriesId, 'Jam No. 156', $this->day(5));

        self::assertSame([$recent, $next, $old, $undated], $this->ids($this->query->search('lantern weekly')));
        self::assertCount(2, $this->query->search('lantern weekly', limit: 2));
    }

    /**
     * H12 scenario 9: drafts, pending and rejected editions or series are never offered
     */
    public function testTypingLeavesOutDraftsAndEditionsOfSeriesThatAreNotPublic(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $public = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $this->scenario->edition($seriesId, 'Jam No. 155', $this->day(1), draft: true);

        $pending = $this->scenario->series('Lantern Pending Jam', public: false);
        $this->scenario->edition($pending, 'Jam No. 157', $this->day(-1));

        $draftSeries = $this->scenario->series('Lantern Draft Jam', draft: true);
        $this->scenario->edition($draftSeries, 'Jam No. 158', $this->day(-1));

        $rejected = $this->scenario->edition($seriesId, 'Jam No. 159', $this->day(-3));
        $this->database->executeStatement('UPDATE competition SET rejected_at = NOW() WHERE id = :id', ['id' => $rejected]);

        self::assertSame([$public], $this->ids($this->query->search('lantern jam')));
    }

    public function testTheShortListIsClosestToTheSolveDayFirstThenUndatedNewestFirst(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $far = $this->scenario->edition($seriesId, 'Jam No. 150', $this->day(-25));
        $undatedOld = $this->scenario->edition($seriesId, 'Spring Special', null);
        $dayBefore = $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-11));
        $undatedNew = $this->scenario->edition($seriesId, 'Summer Special', null);
        $twoAfter = $this->scenario->edition($seriesId, 'Jam No. 155', $this->day(-8));
        $sameDay = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-10));

        $list = $this->query->closest($seriesId, $this->today->modify('-10 days'));

        self::assertSame([$sameDay, $dayBefore, $twoAfter, $far, $undatedNew, $undatedOld], $this->ids($list));
    }

    public function testTheShortListShowsTenAndAlwaysTheMatchedOne(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $editions = [];

        for ($i = 1; $i <= 12; $i++) {
            $editions[] = $this->scenario->edition($seriesId, 'Jam No. ' . (100 + $i), $this->day(-3 * $i));
        }

        $farthest = $editions[11];
        $day = $this->today->modify('-2 days');

        self::assertCount(GetSeriesEditionChoices::SHORT_LIST_LIMIT, $this->query->closest($seriesId, $day));

        $withMatched = $this->query->closest($seriesId, $day, alwaysIncludeId: $farthest);
        self::assertCount(11, $withMatched);
        self::assertSame($farthest, $withMatched[10]->id, 'In its own place - after the ten closest');
    }

    /**
     * H12 scenario 4: a round puzzle the round still keeps secret is neither listed nor found - nor a puzzle hidden by
     * its own hide_until
     */
    public function testTheShortListNamesRevealedRoundPuzzlesOnly(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $editionId = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $revealed = $this->scenario->puzzle('Copper Lighthouse');
        $secret = $this->scenario->puzzle('Whispering Fjord Secret');
        $hidden = $this->scenario->puzzle('Velvet Orchard Hidden');
        $this->scenario->round($editionId, RoundCategory::Solo, $this->day(-2) . ' 19:00', puzzleIds: [$revealed]);
        $this->scenario->round($editionId, RoundCategory::Duo, $this->day(-2) . ' 21:00', puzzleIds: [$secret], secret: true);
        $this->scenario->round($editionId, RoundCategory::Team, $this->day(-2) . ' 22:00', puzzleIds: [$hidden]);
        // Hidden by hand after it was put in the round (no round takes a puzzle hidden by hand)
        $this->database->executeStatement("UPDATE puzzle SET hide_until = NOW() + INTERVAL '30 days' WHERE id = :id", ['id' => $hidden]);

        $choice = $this->onlyChoice($this->query->closest($seriesId, $this->today));

        self::assertSame([RoundCategory::Solo, RoundCategory::Duo, RoundCategory::Team], $choice->categories);
        self::assertSame(['Copper Lighthouse'], $choice->puzzleNames);

        self::assertSame([$editionId], $this->ids($this->query->closest($seriesId, $this->today, 'copper')));
        self::assertSame([], $this->query->closest($seriesId, $this->today, 'whispering'));
        self::assertSame([], $this->query->closest($seriesId, $this->today, 'velvet orchard'));
    }

    public function testTheShortListSearchesEditionNamesAndPuzzleNames(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $no153 = $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $no154 = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $this->scenario->round($no153, RoundCategory::Solo, $this->day(-9) . ' 19:00', puzzleIds: [$this->scenario->puzzle('Starry Harbor')]);

        self::assertSame([$no154], $this->ids($this->query->closest($seriesId, $this->today, 'no. 154')));
        self::assertSame([$no153], $this->ids($this->query->closest($seriesId, $this->today, 'starry harbor')));
        self::assertSame([$no153], $this->ids($this->query->closest($seriesId, $this->today, 'STÁRRY 153')));
        self::assertSame([$no154, $no153], $this->ids($this->query->closest($seriesId, $this->today, 'jam')));
    }

    /**
     * H12 scenario 9 for the short list; H12 scenario 6: a series without editions lists nothing
     */
    public function testTheShortListLeavesOutDraftsAndSeriesThatAreNotPublic(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $public = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $this->scenario->edition($seriesId, 'Jam No. 155', $this->day(-1), draft: true);

        $pending = $this->scenario->series('Lantern Pending Jam', public: false);
        $this->scenario->edition($pending, 'Jam No. 157', $this->day(-1));

        $empty = $this->scenario->series('Copper Kettle Puzzle Cup');

        self::assertSame([$public], $this->ids($this->query->closest($seriesId, $this->today)));
        self::assertSame([], $this->query->closest($pending, $this->today));
        self::assertSame([], $this->query->closest($empty, $this->today));
        self::assertSame([], $this->query->closest('not-a-uuid', $this->today));
    }

    public function testSeriesOfASelectableEdition(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $public = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $draft = $this->scenario->edition($seriesId, 'Jam No. 155', $this->day(-1), draft: true);

        self::assertSame($seriesId, $this->query->seriesOfSelectableEdition($public));
        self::assertTrue($this->query->isSelectableEdition($public));
        self::assertNull($this->query->seriesOfSelectableEdition($draft));
        self::assertNull($this->query->seriesOfSelectableEdition(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND), 'A one-time event is no edition');
        self::assertNull($this->query->seriesOfSelectableEdition('not-a-uuid'));
        self::assertFalse($this->query->isSelectableEdition($seriesId));
    }

    private function day(int $offset): string
    {
        return $this->today->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    /**
     * @param list<SeriesEditionChoice> $choices
     * @return list<string>
     */
    private function ids(array $choices): array
    {
        return array_map(static fn (SeriesEditionChoice $choice): string => $choice->id, $choices);
    }

    /**
     * @param list<SeriesEditionChoice> $choices
     */
    private function onlyChoice(array $choices): SeriesEditionChoice
    {
        self::assertCount(1, $choices);

        return $choices[0];
    }
}
