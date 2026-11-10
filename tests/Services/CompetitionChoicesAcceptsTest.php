<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetSelectableCompetitions;
use SpeedPuzzling\Web\Query\GetSeriesEditionChoices;
use SpeedPuzzling\Web\Services\CompetitionChoicesBuilder;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\CompetitionPickKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the "Competition / event" field accepts is exactly what the picker's list offers
 * (docs/features/events-page/high-frequency-series.md "Validation, submit, prefill"): every id in every state, as every
 * kind of pick, against every kind of current pick and refused submit, compared with an oracle built from the full list
 * statement - the very list the form renders.
 */
final class CompetitionChoicesAcceptsTest extends KernelTestCase
{
    private const string UNKNOWN_ID = '019999aa-0000-7000-8000-000000000000';

    private CompetitionChoicesBuilder $builder;
    private GetSelectableCompetitions $getSelectableCompetitions;
    private GetSeriesEditionChoices $getSeriesEditionChoices;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->builder = self::getContainer()->get(CompetitionChoicesBuilder::class);
        $this->getSelectableCompetitions = self::getContainer()->get(GetSelectableCompetitions::class);
        $this->getSeriesEditionChoices = self::getContainer()->get(GetSeriesEditionChoices::class);
    }

    public function testEveryPickIsAcceptedExactlyWhenTheListOffersIt(): void
    {
        $ids = $this->idsInEveryState();

        /** @var list<null|CompetitionPick> $currents */
        $currents = [
            null,
            CompetitionPick::event(CompetitionFixture::COMPETITION_UNAPPROVED),
            CompetitionPick::event(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND),
            CompetitionPick::edition($ids['draft edition']),
            CompetitionPick::edition($ids['edition of a pending series']),
            CompetitionPick::series($ids['pending series']),
            CompetitionPick::series($ids['public series']),
        ];

        $kinds = [CompetitionPickKind::Event, CompetitionPickKind::Series, CompetitionPickKind::Edition];
        $accepted = 0;
        $refused = 0;

        foreach ($currents as $current) {
            foreach ($ids as $label => $id) {
                foreach ($kinds as $kind) {
                    $pick = new CompetitionPick($kind, $id);

                    // The controllers read the refused submit's pick from the very value being validated
                    foreach ([null, $pick] as $submitted) {
                        $expected = $this->oracle($pick, $current, $submitted);
                        $description = sprintf('%s (%s), current %s, submitted %s', $label, $pick->fieldValue(), $current?->fieldValue() ?? 'none', $submitted?->fieldValue() ?? 'none');

                        self::assertSame($expected, $this->builder->build($current, $submitted)->accepts($pick), $description);

                        $expected ? $accepted++ : $refused++;
                    }
                }
            }
        }

        // Guards the matrix itself: it has to hold both answers
        self::assertGreaterThan(50, $accepted);
        self::assertGreaterThan(200, $refused);
    }

    public function testASpellingTheListNeverOffersIsRefusedEvenWhenPostgresWouldReadIt(): void
    {
        $ids = $this->idsInEveryState();
        $choices = $this->builder->build();

        // Uuid::isValid() lets these through; a list of canonical values never matched them
        self::assertFalse($choices->accepts(CompetitionPick::event('{' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND . '}')));
        self::assertFalse($choices->accepts(CompetitionPick::series('{' . $ids['public series'] . '}')));

        // Upper case is canonicalised by CompetitionPick
        self::assertTrue($choices->accepts(CompetitionPick::event(strtoupper(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND))));
        self::assertTrue($choices->accepts(CompetitionPick::series(strtoupper($ids['public series']))));
    }

    public function testASeriesTurningPrivateIsRefusedAndABareOneTimeIdIsNoEdition(): void
    {
        $ids = $this->idsInEveryState();

        $choices = $this->builder->build();
        self::assertFalse($choices->accepts(CompetitionPick::edition(EventDetailFixture::COMPETITION_HILLTOP_WEEKEND)));
        self::assertTrue($choices->accepts(CompetitionPick::series($ids['public series'])));

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_series SET is_draft = true WHERE id = :id',
            ['id' => $ids['public series']],
        );

        self::assertFalse($this->builder->build()->accepts(CompetitionPick::series($ids['public series'])));
    }

    /**
     * @return array<string, string>
     */
    private function idsInEveryState(): array
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $today = self::getContainer()->get(ClockInterface::class)->now()->format('Y-m-d');

        $publicSeries = $scenario->series('Lantern Weekly Jam');
        $pendingSeries = $scenario->series('Willow Lane Puzzle Nights', public: false);
        $draftSeries = $scenario->series('Quiet Harbor Puzzle Series', draft: true);

        return [
            'public one-time event' => EventDetailFixture::COMPETITION_HILLTOP_WEEKEND,
            'another public one-time event' => EventsPageFixture::COMPETITION_RIVERSIDE_OPEN,
            'pending one-time event' => CompetitionFixture::COMPETITION_UNAPPROVED,
            'rejected one-time event' => EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED,
            'draft one-time event' => OrganizationFixture::COMPETITION_DRAFT_NIGHT,
            'public series' => $publicSeries,
            'pending series' => $pendingSeries,
            'draft series' => $draftSeries,
            'rejected series' => EventsPageFixture::SERIES_OLD_MILL_REJECTED,
            'public edition' => $scenario->edition($publicSeries, 'Jam No. 154', $today),
            'draft edition' => $scenario->edition($publicSeries, 'Jam No. 163', $today, draft: true),
            'edition of a pending series' => $scenario->edition($pendingSeries, 'Night 1', $today),
            'edition of a draft series' => $scenario->edition($draftSeries, 'Evening 1', $today),
            'edition of a rejected series' => EventsPageFixture::EDITION_OLD_MILL,
            'unknown' => self::UNKNOWN_ID,
        ];
    }

    /**
     * The rule as the full list states it: the list statement's values, the current pick (a bare uuid of the current
     * edition counts as that edition) and a publicly visible edition picked by typing.
     */
    private function oracle(CompetitionPick $pick, null|CompetitionPick $current, null|CompetitionPick $submitted): bool
    {
        if ($current !== null && $current->id === $pick->id) {
            if ($current->equals($pick) || ($pick->kind === CompetitionPickKind::Event && $current->kind === CompetitionPickKind::Edition)) {
                return true;
            }
        }

        $offered = [];
        $submittedEditionId = $submitted?->kind === CompetitionPickKind::Edition ? $submitted->id : null;

        foreach ($this->getSelectableCompetitions->all($current, $submittedEditionId) as $competition) {
            $offered[$competition->pick()->fieldValue()] = true;
        }

        if (isset($offered[$pick->fieldValue()])) {
            return true;
        }

        if ($pick->kind === CompetitionPickKind::Series) {
            return false;
        }

        return isset($offered[CompetitionPick::edition($pick->id)->fieldValue()])
            || $this->getSeriesEditionChoices->seriesOfSelectableEdition($pick->id) !== null;
    }
}
