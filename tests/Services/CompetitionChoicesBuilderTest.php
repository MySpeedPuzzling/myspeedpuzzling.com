<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\CompetitionChoicesBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Value\CompetitionChoices;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CompetitionChoicesBuilderTest extends KernelTestCase
{
    private const string HTML_NAMED_COMPETITION = '018d0004-0000-0000-0000-0000000000b1';

    private CompetitionChoicesBuilder $builder;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->builder = self::getContainer()->get(CompetitionChoicesBuilder::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testEditionsAreGroupedUnderTheirSeries(): void
    {
        $choices = $this->builder->build();

        $edition = $this->option($choices, CompetitionSeriesFixture::EDITION_EJJ_68);
        self::assertSame(CompetitionSeriesFixture::SERIES_EJJ, $edition['optgroup'] ?? null);

        $standalone = $this->option($choices, CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertArrayNotHasKey('optgroup', $standalone);

        $optgroupsByValue = [];
        foreach ($choices->optgroups as $optgroup) {
            $optgroupsByValue[$optgroup['value']] = $optgroup;
        }

        self::assertArrayHasKey(CompetitionSeriesFixture::SERIES_EJJ, $optgroupsByValue);
        self::assertSame('Euro Jigsaw Jam', $optgroupsByValue[CompetitionSeriesFixture::SERIES_EJJ]['label']);
        self::assertArrayHasKey(CompetitionSeriesFixture::SERIES_OFFLINE, $optgroupsByValue);
        self::assertArrayHasKey(CompetitionSeriesFixture::SERIES_PAST_ONLY, $optgroupsByValue);
        // Exactly one optgroup per series, no matter how many editions it has
        self::assertCount(count($optgroupsByValue), $choices->optgroups);
    }

    public function testUnapprovedSeriesHasNoOptgroupAndItsEditionIsNotOffered(): void
    {
        $choices = $this->builder->build();

        foreach ($choices->optgroups as $optgroup) {
            self::assertNotSame(CompetitionSeriesFixture::SERIES_UNAPPROVED, $optgroup['value']);
        }

        self::assertFalse($choices->contains(CompetitionSeriesFixture::EDITION_UNAPPROVED_1));
    }

    public function testContainsReflectsExactlyTheOfferedOptions(): void
    {
        $choices = $this->builder->build();

        self::assertTrue($choices->contains(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertTrue($choices->contains(CompetitionSeriesFixture::EDITION_EJJ_69));
        self::assertFalse($choices->contains(CompetitionFixture::COMPETITION_UNAPPROVED));
        self::assertFalse($choices->contains('not-a-uuid'));

        $withCurrent = $this->builder->build(CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertTrue($withCurrent->contains(CompetitionFixture::COMPETITION_UNAPPROVED));
        $values = array_column($withCurrent->options, 'value');
        self::assertCount(1, array_keys($values, CompetitionFixture::COMPETITION_UNAPPROVED, true));
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

        $option = $this->option($this->builder->build(), self::HTML_NAMED_COMPETITION);

        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $option['text']);
        self::assertStringNotContainsString('<b>x</b>', $option['text']);
        self::assertStringContainsString('&lt;i&gt;Nowhere&lt;/i&gt; &amp; &quot;there&quot;', $option['text']);
        // keywords are plain text - TomSelect matches them as-is, they are never rendered
        self::assertSame('<b>x</b> <i>Nowhere</i> & "there"', $option['keywords']);
    }

    public function testKeywordsCarrySeriesAndEditionNames(): void
    {
        $option = $this->option($this->builder->build(), CompetitionSeriesFixture::EDITION_EJJ_68);

        self::assertStringContainsString('Euro Jigsaw Jam', $option['keywords']);
        self::assertStringContainsString('EJJ #68', $option['keywords']);
        // The card itself names the series so the selected item stays self-descriptive
        self::assertStringContainsString('Euro Jigsaw Jam', $option['text']);
        self::assertStringContainsString('competition-option', $option['text']);
    }

    public function testLogoIsLazyLoadedAndFallsBackToTheSeriesLogo(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_series SET logo = :logo WHERE id = :id',
            ['logo' => 'competitions/ejj-series.png', 'id' => CompetitionSeriesFixture::SERIES_EJJ],
        );
        $this->database->executeStatement(
            'UPDATE competition SET logo = :logo WHERE id = :id',
            ['logo' => 'competitions/wjpc.png', 'id' => CompetitionFixture::COMPETITION_WJPC_2024],
        );

        $choices = $this->builder->build();

        $standalone = $this->option($choices, CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertStringContainsString('loading="lazy"', $standalone['text']);
        self::assertStringContainsString('competition-option-logo', $standalone['text']);
        self::assertStringContainsString('competitions/wjpc.png', $standalone['text']);

        $edition = $this->option($choices, CompetitionSeriesFixture::EDITION_EJJ_68);
        self::assertStringContainsString('competitions/ejj-series.png', $edition['text']);
        self::assertStringContainsString('loading="lazy"', $edition['text']);

        $ejjOptgroup = null;
        foreach ($choices->optgroups as $optgroup) {
            if ($optgroup['value'] === CompetitionSeriesFixture::SERIES_EJJ) {
                $ejjOptgroup = $optgroup;
            }
        }
        self::assertNotNull($ejjOptgroup);
        self::assertStringContainsString('competitions/ejj-series.png', $ejjOptgroup['logo'] ?? '');

        // No logo anywhere - no <img> at all
        $czech = $this->option($choices, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);
        self::assertStringNotContainsString('<img', $czech['text']);
    }

    public function testLiveEventsCarryTheLiveBadge(): void
    {
        $this->database->executeStatement(
            "UPDATE competition SET date_from = now(), date_to = now() + INTERVAL '2 days' WHERE id = :id",
            ['id' => CompetitionFixture::COMPETITION_RECURRING_ONLINE],
        );

        $choices = $this->builder->build();

        self::assertStringContainsString('>live</span>', $this->option($choices, CompetitionFixture::COMPETITION_RECURRING_ONLINE)['text']);
        self::assertStringNotContainsString('>live</span>', $this->option($choices, CompetitionFixture::COMPETITION_WJPC_2024)['text']);
    }

    /**
     * @return array{value: string, text: string, keywords: string, optgroup?: string}
     */
    private function option(CompetitionChoices $choices, string $competitionId): array
    {
        foreach ($choices->options as $option) {
            if ($option['value'] === $competitionId) {
                return $option;
            }
        }

        self::fail(sprintf('Option %s is not offered', $competitionId));
    }
}
