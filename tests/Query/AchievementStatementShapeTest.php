<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use SpeedPuzzling\Web\Query\AchievementSqlRules;
use SpeedPuzzling\Web\Query\GetAchievementHolders;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\HeldAtLaunch;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the achievement statements start from, so their cost does not grow with everything else in the database:
 * the holders list walks each tier newest first and stops at its limit (no window over every holder of the type),
 * the countries start from the members, Competitor from the player's own entries and results (not from every
 * competition). The rows are pinned by GetAchievementHoldersParityTest and CompetitorEventsParityTest.
 */
final class AchievementStatementShapeTest extends KernelTestCase
{
    public function testTheHoldersListStopsAtEachTiersLimitAndTheCountriesStartFromTheMembers(): void
    {
        self::bootKernel();
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $holders = self::getContainer()->get(GetAchievementHolders::class);

        $debugDataHolder->reset();
        $holders->forType(BadgeType::ZenPuzzler, null, new HeldAtLaunch(null, new DateTimeImmutable('2026-08-02')));
        $holders->countries(BadgeType::ZenPuzzler);

        $statements = [];
        foreach ($debugDataHolder->getData() as $queries) {
            foreach ($queries as $query) {
                if (is_string($query['sql'] ?? null)) {
                    $statements[] = $query['sql'];
                }
            }
        }

        self::assertCount(3, $statements);
        [$listed, , $countries] = $statements;
        self::assertStringContainsString('CROSS JOIN LATERAL', $listed);
        self::assertStringNotContainsString('OVER (', $listed);
        self::assertMatchesRegularExpression('/FROM membership m\s+JOIN player p/', $countries);
    }

    public function testCompetitorStartsFromThePlayersOwnEntriesAndResults(): void
    {
        self::assertStringNotContainsString('FROM competition c', AchievementSqlRules::COMPETITOR_EVENTS);
        self::assertStringNotContainsString('LATERAL', AchievementSqlRules::COMPETITOR_EVENTS);
        self::assertStringContainsString('JOIN competition c ON c.id = taken_part.competition_id', AchievementSqlRules::COMPETITOR_EVENTS);
    }
}
