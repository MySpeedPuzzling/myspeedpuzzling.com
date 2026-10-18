<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * POST /internal-api/competitions/{id}/duplicate (docs/features/internal-api.md "Competitions and events"): a draft copy
 * added by the reviewer player, 201 with its JSON; optional name / dates / location overrides, validated.
 */
final class DuplicateCompetitionInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testDuplicatesAnEventWithItsRoundsAsADraft(): void
    {
        $browser = self::createClient();

        $copy = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/duplicate');

        self::assertResponseStatusCodeSame(201);
        self::assertNotSame(OfficialResultsFixture::COMPETITION_RESULTS_CUP, $copy['competitionId']);
        self::assertSame('Results Cup', $copy['name']);
        self::assertNotSame('results-cup', $copy['slug']);
        self::assertTrue($copy['draft']);
        self::assertNull($copy['resultsLink']);
        self::assertIsArray($copy['rounds']);
        self::assertSame(['Group A', 'Group B', 'Pairs', 'Final', 'Pairs Final'], array_column($copy['rounds'], 'name'));

        foreach ($copy['rounds'] as $round) {
            self::assertIsArray($round);
            self::assertSame([], $round['puzzles']);
            self::assertSame(0, $round['resultsCount']);
        }
    }

    public function testOverridesTheNameDatesAndPlace(): void
    {
        $browser = self::createClient();

        $copy = self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN . '/duplicate', [
            'name' => 'Riverbend Autumn Open',
            'dateFrom' => '2031-10-04',
            'dateTo' => '2031-10-05',
            'location' => 'Riverbend Library',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Riverbend Autumn Open', $copy['name']);
        self::assertStringStartsWith('riverbend-autumn-open', self::text($copy['slug']));
        self::assertSame('Riverbend Library', $copy['location']);
        self::assertStringStartsWith('2031-10-04', self::text($copy['dateFrom']));
        self::assertStringStartsWith('2031-10-05', self::text($copy['dateTo']));
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $copy['organizationId']);
        self::assertTrue($copy['draft']);
    }

    public function testRefusesAnInvalidOverride(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/duplicate', [
            'dateFrom' => '2031-10-05',
            'dateTo' => '2031-10-01',
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testUnknownCompetitionIsNotFound(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', '/internal-api/competitions/018d0004-0000-0000-0000-0000000000ff/duplicate');

        self::assertResponseStatusCodeSame(404);
    }

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
