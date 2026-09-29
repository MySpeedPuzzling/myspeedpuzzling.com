<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CountCompetitionResultsTest extends KernelTestCase
{
    private CountCompetitionResults $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(CountCompetitionResults::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testCountsTheTimesLinkedToTheCompetition(): void
    {
        // Qualification TIME_09-11 and final TIME_19-20
        self::assertSame(5, $this->query->forCompetition(CompetitionFixture::COMPETITION_WJPC_2024));
        self::assertSame(0, $this->query->forCompetition(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));
    }

    public function testSuspiciousTimesDoNotCount(): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET suspicious = true WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_09],
        );

        self::assertSame(4, $this->query->forCompetition(CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testInvalidIdCountsNothing(): void
    {
        self::assertSame(0, $this->query->forCompetition('not-a-uuid'));
    }
}
