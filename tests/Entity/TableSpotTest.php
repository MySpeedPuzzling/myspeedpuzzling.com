<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\RoundTable;
use SpeedPuzzling\Web\Entity\TableRow;
use SpeedPuzzling\Web\Entity\TableSpot;

final class TableSpotTest extends TestCase
{
    public function testAssignPlayerClearsManualName(): void
    {
        $spot = $this->createSpot();
        $spot->assignManualName('Manual Name');
        self::assertSame('Manual Name', $spot->playerName);

        $player = $this->createPlayer();
        $spot->assignPlayer($player);

        self::assertSame($player, $spot->player);
        self::assertNull($spot->playerName);
    }

    public function testAssignManualNameClearsPlayer(): void
    {
        $spot = $this->createSpot();
        $player = $this->createPlayer();
        $spot->assignPlayer($player);
        self::assertSame($player, $spot->player);

        $spot->assignManualName('Manual Name');

        self::assertSame('Manual Name', $spot->playerName);
        self::assertNull($spot->player);
    }

    public function testClearAssignment(): void
    {
        $spot = $this->createSpot();
        $player = $this->createPlayer();
        $spot->assignPlayer($player);

        $spot->clearAssignment();

        self::assertNull($spot->player);
        self::assertNull($spot->playerName);
    }

    private function createSpot(): TableSpot
    {
        $competition = new Competition(
            id: Uuid::uuid7(),
            name: 'Test Competition',
            slug: null,
            shortcut: null,
            logo: null,
            description: null,
            link: null,
            registrationLink: null,
            resultsLink: null,
            location: 'Test',
            locationCountryCode: null,
            dateFrom: null,
            dateTo: null,
            tag: null,
        );

        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: 'Test Round',
            minutesLimit: 60,
            startsAt: new DateTimeImmutable(),
        );

        $row = new TableRow(
            id: Uuid::uuid7(),
            round: $round,
            position: 1,
        );

        $table = new RoundTable(
            id: Uuid::uuid7(),
            row: $row,
            position: 1,
            label: 'Table 1',
        );

        return new TableSpot(
            id: Uuid::uuid7(),
            table: $table,
            position: 1,
        );
    }

    private function createPlayer(): Player
    {
        return new Player(
            id: Uuid::uuid7(),
            code: 'test',
            userId: 'auth0|test',
            email: 'test@test.com',
            name: 'Test Player',
            registeredAt: new DateTimeImmutable(),
        );
    }
}
