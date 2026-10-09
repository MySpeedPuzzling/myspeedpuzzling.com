<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetSeriesEditionChoices;
use SpeedPuzzling\Web\Services\CompetitionChoicesBuilder;
use SpeedPuzzling\Web\Services\CompetitionPickerDate;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\CompetitionChoices;
use SpeedPuzzling\Web\Value\CompetitionPick;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The picker's TomSelect payload and what it accepts (docs/features/events-page/high-frequency-series.md "The form").
 */
final class CompetitionChoicesBuilderTest extends KernelTestCase
{
    private const string HTML_NAMED_COMPETITION = '018d0004-0000-0000-0000-0000000000b1';
    private const string UNKNOWN_ID = '019999aa-0000-7000-8000-000000000000';

    private CompetitionChoicesBuilder $builder;
    private Connection $database;
    private SeriesEditionScenario $scenario;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->builder = self::getContainer()->get(CompetitionChoicesBuilder::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->scenario = new SeriesEditionScenario(self::getContainer());
        $this->today = self::getContainer()->get(ClockInterface::class)->now();
    }

    public function testASeriesIsOneUngroupedOptionWithItsCard(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $this->scenario->edition($seriesId, 'Jam No. 155', $this->day(5));

        $choices = $this->builder->build();
        $option = $this->option($choices, 'series:' . $seriesId);

        self::assertArrayNotHasKey('optgroup', $option);
        self::assertStringContainsString('sp-series-option', $option['text']);
        self::assertStringContainsString('Lantern Weekly Jam', $option['text']);
        // The picker's one date style, and how many dates the series has - like a one-time event's dates
        $next = self::getContainer()->get(CompetitionPickerDate::class)->format(new DateTimeImmutable($this->day(5)));
        self::assertStringContainsString('Next: ' . $next . ' · 2 dates', $option['text']);
        self::assertStringContainsString('Online', $option['text']);
        self::assertStringNotContainsString('Jam No.', $option['text'], 'No edition on the series card');
        self::assertSame([], array_filter($choices->optgroups, static fn (array $optgroup): bool => $optgroup['value'] === $seriesId));
    }

    public function testASeriesCardSaysLastOrNoDatesYetAndLive(): void
    {
        $past = $this->scenario->series('Moonlit Puzzle Sprints', online: false);
        $this->scenario->edition($past, 'Sprint 1', $this->day(-4));

        // H13: a series without editions
        $empty = $this->scenario->series('Copper Kettle Puzzle Cup');

        $live = $this->scenario->series('Starling Puzzle Afternoons');
        $this->scenario->edition($live, 'Afternoon 1', $this->day(0));

        $undated = $this->scenario->series('Moonlit Pier Puzzle Club');
        $this->scenario->edition($undated, 'Pier Meet 1', null);

        $choices = $this->builder->build();

        $pastCard = $this->option($choices, 'series:' . $past)['text'];
        self::assertStringContainsString('Last: ', $pastCard);
        self::assertStringContainsString(' · 1 date<', $pastCard);
        // An offline series shows its place, not "Online"
        self::assertStringContainsString('Harbor Town', $pastCard);
        self::assertStringContainsString('fi-cz', $pastCard);

        $emptyCard = $this->option($choices, 'series:' . $empty)['text'];
        self::assertStringContainsString('No dates yet', $emptyCard);
        self::assertStringNotContainsString(' date<', $emptyCard);
        $undatedCard = $this->option($choices, 'series:' . $undated)['text'];
        self::assertStringContainsString('No dates yet', $undatedCard, 'an undated edition is no date');
        self::assertStringNotContainsString(' date<', $undatedCard);

        $liveCard = $this->option($choices, 'series:' . $live)['text'];
        self::assertStringContainsString('>live</span>', $liveCard);
        self::assertStringContainsString('>1 date<', $liveCard, 'a live series shows its count - neither next nor last');
        self::assertStringNotContainsString('>live</span>', $pastCard);
    }

    /**
     * Every date of the picker in one style (CompetitionPickerDate): a one-time event's days, an edition's
     */
    public function testCardsShowDatesInThePickersStyle(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $editionId = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $date = self::getContainer()->get(CompetitionPickerDate::class);

        $edition = $this->option($this->builder->build(CompetitionPick::edition($editionId)), 'edition:' . $editionId)['text'];
        self::assertStringContainsString('>' . $date->format(new DateTimeImmutable($this->day(-2))) . '</small>', $edition);

        $typed = $this->builder->editionsPayload(self::getContainer()->get(GetSeriesEditionChoices::class)->search('jam no. 154'))['options'][0]['text'];
        self::assertStringContainsString('>' . $date->format(new DateTimeImmutable($this->day(-2))) . '</small>', $typed);

        $this->database->executeStatement(
            'UPDATE competition SET date_from = :from, date_to = :to WHERE id = :id',
            ['from' => '2025-10-10', 'to' => '2025-10-12', 'id' => EventDetailFixture::COMPETITION_HILLTOP_WEEKEND],
        );
        $oneTime = $this->option($this->builder->build(), EventDetailFixture::COMPETITION_HILLTOP_WEEKEND)['text'];
        self::assertStringContainsString('>10–12 Oct 2025</small>', $oneTime);
    }

    public function testASeriesIsFoundByItsOrganizationsNames(): void
    {
        $keywords = $this->option($this->builder->build(), 'series:' . OrganizationFixture::SERIES_RIVERBEND_VIRTUAL)['keywords'];

        self::assertStringContainsString(OrganizationFixture::SERIES_RIVERBEND_VIRTUAL_NAME, $keywords);
        self::assertStringContainsString(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $keywords);
        self::assertStringContainsString('RJA', $keywords);
    }

    public function testOrganiserAuthoredStringsAreEscaped(): void
    {
        $this->database->executeStatement(
            <<<SQL
            INSERT INTO competition (id, name, location, is_online, approved_at)
            VALUES (:id, '<b>x</b>', '<i>Nowhere</i> & "there"', false, now())
            SQL,
            ['id' => self::HTML_NAMED_COMPETITION],
        );
        $seriesId = $this->scenario->series('<b>Lantern</b> & "Jam"');

        $choices = $this->builder->build();

        $option = $this->option($choices, self::HTML_NAMED_COMPETITION);
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $option['text']);
        self::assertStringNotContainsString('<b>x</b>', $option['text']);
        self::assertStringContainsString('&lt;i&gt;Nowhere&lt;/i&gt; &amp; &quot;there&quot;', $option['text']);
        // keywords are plain text - TomSelect matches them as-is, they are never rendered
        self::assertSame('<b>x</b> <i>Nowhere</i> & "there"', $option['keywords']);

        $series = $this->option($choices, 'series:' . $seriesId);
        self::assertStringContainsString('&lt;b&gt;Lantern&lt;/b&gt; &amp; &quot;Jam&quot;', $series['text']);
        self::assertStringNotContainsString('<b>Lantern</b>', $series['text']);
    }

    public function testTheCurrentEditionIsGroupedUnderItsSeries(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $editionId = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $this->database->executeStatement(
            'UPDATE competition_series SET logo = :logo WHERE id = :id',
            ['logo' => 'competitions/lantern-series.png', 'id' => $seriesId],
        );

        $choices = $this->builder->build(CompetitionPick::edition($editionId));
        $option = $this->option($choices, 'edition:' . $editionId);

        self::assertSame($seriesId, $option['optgroup'] ?? null);
        self::assertStringContainsString('Jam No. 154', $option['text']);
        // The card names the series, so the selected item stays self-descriptive
        self::assertStringContainsString('Lantern Weekly Jam', $option['text']);
        // The edition has no logo of its own - the series' one, lazy loaded
        self::assertStringContainsString('competitions/lantern-series.png', $option['text']);
        self::assertStringContainsString('loading="lazy"', $option['text']);

        $optgroups = array_values(array_filter($choices->optgroups, static fn (array $optgroup): bool => $optgroup['value'] === $seriesId));
        self::assertCount(1, $optgroups);
        self::assertSame('Lantern Weekly Jam', $optgroups[0]['label']);
        self::assertStringContainsString('competitions/lantern-series.png', $optgroups[0]['logo'] ?? '');
    }

    public function testAcceptsMatrix(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $publicEdition = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $draftEdition = $this->scenario->edition($seriesId, 'Jam No. 163', $this->day(7), draft: true);
        $pendingSeries = $this->scenario->series('Willow Lane Puzzle Nights', public: false);
        $pendingSeriesEdition = $this->scenario->edition($pendingSeries, 'Night 1', $this->day(-1));

        $choices = $this->builder->build();

        // One-time events and series: what was offered
        self::assertTrue($choices->accepts(CompetitionPick::event(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND)));
        self::assertFalse($choices->accepts(CompetitionPick::event(CompetitionFixture::COMPETITION_UNAPPROVED)));
        self::assertTrue($choices->accepts(CompetitionPick::series($seriesId)));
        self::assertFalse($choices->accepts(CompetitionPick::series($pendingSeries)));
        self::assertFalse($choices->accepts(CompetitionPick::series($publicEdition)), 'An edition id is no series');
        self::assertFalse($choices->accepts(CompetitionPick::event(self::UNKNOWN_ID)));

        // Editions: publicly visible ones (typed or picked from the short list), never a draft or a pending series' one
        self::assertTrue($choices->accepts(CompetitionPick::edition($publicEdition)));
        self::assertFalse($choices->accepts(CompetitionPick::edition($draftEdition)));
        self::assertFalse($choices->accepts(CompetitionPick::edition($pendingSeriesEdition)));
        self::assertFalse($choices->accepts(CompetitionPick::edition(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND)), 'A one-time event is no edition');

        // P2: a bare uuid of an edition, posted by a form an older release rendered
        self::assertTrue($choices->accepts(CompetitionPick::event($publicEdition)));
        self::assertFalse($choices->accepts(CompetitionPick::event($draftEdition)));

        // The edited time's current link is accepted whatever its state - also as a bare uuid
        $withCurrent = $this->builder->build(CompetitionPick::edition($draftEdition));
        self::assertTrue($withCurrent->offers('edition:' . $draftEdition));
        self::assertTrue($withCurrent->accepts(CompetitionPick::edition($draftEdition)));
        self::assertTrue($withCurrent->accepts(CompetitionPick::event($draftEdition)));
        self::assertFalse($withCurrent->accepts(CompetitionPick::series($draftEdition)));

        $withCurrentSeries = $this->builder->build(CompetitionPick::series($pendingSeries));
        self::assertTrue($withCurrentSeries->accepts(CompetitionPick::series($pendingSeries)));

        $withCurrentEvent = $this->builder->build(CompetitionPick::event(CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertTrue($withCurrentEvent->accepts(CompetitionPick::event(CompetitionFixture::COMPETITION_UNAPPROVED)));
        $values = array_column($withCurrentEvent->options, 'value');
        self::assertCount(1, array_keys($values, CompetitionFixture::COMPETITION_UNAPPROVED, true));
    }

    public function testARefusedSubmitsEditionIsOfferedAgainOnlyWhilePublic(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $publicEdition = $this->scenario->edition($seriesId, 'Jam No. 154', $this->day(-2));
        $draftEdition = $this->scenario->edition($seriesId, 'Jam No. 164', $this->day(8), draft: true);

        self::assertTrue($this->builder->build(null, CompetitionPick::edition($publicEdition))->offers('edition:' . $publicEdition));
        self::assertFalse($this->builder->build(null, CompetitionPick::edition($draftEdition))->offers('edition:' . $draftEdition));
        // Never baked in otherwise
        self::assertFalse($this->builder->build()->offers('edition:' . $publicEdition));
    }

    public function testTypedSearchPayloadGroupsEditionsUnderTheirSeries(): void
    {
        $seriesId = $this->scenario->series('Lantern Weekly Jam');
        $this->scenario->edition($seriesId, 'Jam No. 153', $this->day(-9));
        $this->scenario->edition($seriesId, 'Jam No. 154 <script>', $this->day(-2));

        $editions = self::getContainer()->get(GetSeriesEditionChoices::class)->search('jam no. 15');
        $payload = $this->builder->editionsPayload($editions);

        self::assertCount(2, $payload['options']);
        self::assertCount(1, $payload['optgroups']);
        self::assertSame(['value' => $seriesId, 'label' => 'Lantern Weekly Jam'], $payload['optgroups'][0]);

        foreach ($payload['options'] as $option) {
            self::assertStringStartsWith('edition:', $option['value']);
            self::assertSame($seriesId, $option['optgroup']);
            self::assertStringContainsString('Lantern Weekly Jam', $option['keywords']);
            self::assertStringNotContainsString('<script>', $option['text']);
        }
    }

    public function testOneTimeEventLogoIsLazyLoaded(): void
    {
        $this->database->executeStatement(
            'UPDATE competition SET logo = :logo WHERE id = :id',
            ['logo' => 'competitions/hilltop.png', 'id' => EventDetailFixture::COMPETITION_HILLTOP_WEEKEND],
        );

        $choices = $this->builder->build();

        $option = $this->option($choices, EventDetailFixture::COMPETITION_HILLTOP_WEEKEND);
        self::assertStringContainsString('loading="lazy"', $option['text']);
        self::assertStringContainsString('competition-option-logo', $option['text']);
        self::assertStringContainsString('competitions/hilltop.png', $option['text']);
        self::assertArrayNotHasKey('optgroup', $option);
    }

    private function day(int $offset): string
    {
        return $this->today->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    /**
     * @return array{value: string, text: string, keywords: string, optgroup?: string}
     */
    private function option(CompetitionChoices $choices, string $value): array
    {
        foreach ($choices->options as $option) {
            if ($option['value'] === $value) {
                return $option;
            }
        }

        self::fail(sprintf('Option %s is not offered', $value));
    }
}
