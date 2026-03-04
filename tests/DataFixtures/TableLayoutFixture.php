<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\RoundTable;
use SpeedPuzzling\Web\Entity\TableRow;
use SpeedPuzzling\Web\Entity\TableSpot;

final class TableLayoutFixture extends Fixture implements DependentFixtureInterface
{
    public const string TABLE_ROW_1 = '018d0010-0000-0000-0000-000000000001';
    public const string TABLE_ROW_2 = '018d0010-0000-0000-0000-000000000002';
    public const string ROUND_TABLE_1 = '018d0011-0000-0000-0000-000000000001';
    public const string ROUND_TABLE_2 = '018d0011-0000-0000-0000-000000000002';
    public const string ROUND_TABLE_3 = '018d0011-0000-0000-0000-000000000003';
    public const string ROUND_TABLE_4 = '018d0011-0000-0000-0000-000000000004';
    public const string SPOT_ASSIGNED_PLAYER = '018d0012-0000-0000-0000-000000000001';
    public const string SPOT_MANUAL_NAME = '018d0012-0000-0000-0000-000000000002';
    public const string SPOT_EMPTY = '018d0012-0000-0000-0000-000000000003';

    public function load(ObjectManager $manager): void
    {
        $round = $this->getReference(CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRound::class);
        $player = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);

        // Row 1
        $row1 = new TableRow(
            id: Uuid::fromString(self::TABLE_ROW_1),
            round: $round,
            position: 1,
            label: 'Row 1',
        );
        $manager->persist($row1);
        $this->addReference(self::TABLE_ROW_1, $row1);

        // Row 2
        $row2 = new TableRow(
            id: Uuid::fromString(self::TABLE_ROW_2),
            round: $round,
            position: 2,
            label: 'Row 2',
        );
        $manager->persist($row2);
        $this->addReference(self::TABLE_ROW_2, $row2);

        // Table 1 in Row 1
        $table1 = new RoundTable(
            id: Uuid::fromString(self::ROUND_TABLE_1),
            row: $row1,
            position: 1,
            label: 'Table 1',
        );
        $manager->persist($table1);
        $this->addReference(self::ROUND_TABLE_1, $table1);

        // Table 2 in Row 1
        $table2 = new RoundTable(
            id: Uuid::fromString(self::ROUND_TABLE_2),
            row: $row1,
            position: 2,
            label: 'Table 2',
        );
        $manager->persist($table2);
        $this->addReference(self::ROUND_TABLE_2, $table2);

        // Table 3 in Row 2
        $table3 = new RoundTable(
            id: Uuid::fromString(self::ROUND_TABLE_3),
            row: $row2,
            position: 1,
            label: 'Table 3',
        );
        $manager->persist($table3);
        $this->addReference(self::ROUND_TABLE_3, $table3);

        // Table 4 in Row 2
        $table4 = new RoundTable(
            id: Uuid::fromString(self::ROUND_TABLE_4),
            row: $row2,
            position: 2,
            label: 'Table 4',
        );
        $manager->persist($table4);
        $this->addReference(self::ROUND_TABLE_4, $table4);

        // Spot with assigned player (Table 1)
        $spotAssigned = new TableSpot(
            id: Uuid::fromString(self::SPOT_ASSIGNED_PLAYER),
            table: $table1,
            position: 1,
            player: $player,
        );
        $manager->persist($spotAssigned);

        // Spot with manual name (Table 1)
        $spotManual = new TableSpot(
            id: Uuid::fromString(self::SPOT_MANUAL_NAME),
            table: $table1,
            position: 2,
            playerName: 'Manual Player',
        );
        $manager->persist($spotManual);

        // Empty spot (Table 2)
        $spotEmpty = new TableSpot(
            id: Uuid::fromString(self::SPOT_EMPTY),
            table: $table2,
            position: 1,
        );
        $manager->persist($spotEmpty);

        // Spots for Table 2 (second spot)
        $spot4 = new TableSpot(
            id: Uuid::uuid7(),
            table: $table2,
            position: 2,
        );
        $manager->persist($spot4);

        // Spots for Table 3
        $spot5 = new TableSpot(
            id: Uuid::uuid7(),
            table: $table3,
            position: 1,
        );
        $manager->persist($spot5);

        $spot6 = new TableSpot(
            id: Uuid::uuid7(),
            table: $table3,
            position: 2,
        );
        $manager->persist($spot6);

        // Spots for Table 4
        $spot7 = new TableSpot(
            id: Uuid::uuid7(),
            table: $table4,
            position: 1,
        );
        $manager->persist($spot7);

        $spot8 = new TableSpot(
            id: Uuid::uuid7(),
            table: $table4,
            position: 2,
        );
        $manager->persist($spot8);

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            CompetitionRoundFixture::class,
            PlayerFixture::class,
        ];
    }
}
