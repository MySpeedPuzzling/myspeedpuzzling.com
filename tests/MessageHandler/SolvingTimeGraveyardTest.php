<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Message\PruneSolvingTimeGraveyard;
use SpeedPuzzling\Web\MessageHandler\PruneSolvingTimeGraveyardHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/api/solving-time-sync.md "Graveyard"
 */
final class SolvingTimeGraveyardTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testDeletingOnTheWebBuriesTheTime(): void
    {
        $this->messageBus->dispatch(new DeletePuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_12,
        ));

        self::assertSame('web', $this->database->fetchOne(
            'SELECT deleted_via FROM solving_time_graveyard WHERE solving_time_id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        ));

        // A pair time: a tombstone for each registered member
        $members = $this->database->fetchFirstColumn(
            'SELECT player_id FROM solving_time_tombstone WHERE solving_time_id = :id ORDER BY player_id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        $expected = [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE];
        sort($expected);
        self::assertSame($expected, $members);
    }

    public function testAccountDeletionLeavesNoGraveAndWipesTheOldOnes(): void
    {
        // An earlier deletion of the player's
        $this->messageBus->dispatch(new DeletePuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
        ));
        self::assertSame(1, $this->rowsOf('solving_time_graveyard', PlayerFixture::PLAYER_REGULAR));

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));

        self::assertSame(0, $this->rowsOf('solving_time_graveyard', PlayerFixture::PLAYER_REGULAR));
        self::assertSame(0, $this->rowsOf('solving_time_tombstone', PlayerFixture::PLAYER_REGULAR));
        // Their remaining times went without a grave
        self::assertSame(0, self::rowCount($this->database, 'SELECT COUNT(*) FROM solving_time_graveyard'));
    }

    public function testPruneKeepsTwentyFourMonths(): void
    {
        foreach (['-25 months' => 'old', '-23 months' => 'recent'] as $age => $label) {
            $deletedAt = TestClock::now()->modify($age)->format('Y-m-d H:i:s');
            $this->database->executeStatement(
                "INSERT INTO solving_time_graveyard (id, solving_time_id, player_id, deleted_at, deleted_via, snapshot) VALUES (gen_random_uuid(), gen_random_uuid(), :playerId, :deletedAt, 'web', :snapshot)",
                ['playerId' => PlayerFixture::PLAYER_ADMIN, 'deletedAt' => $deletedAt, 'snapshot' => json_encode(['label' => $label])],
            );
            $this->database->executeStatement(
                'INSERT INTO solving_time_tombstone (id, solving_time_id, player_id, deleted_at) VALUES (gen_random_uuid(), gen_random_uuid(), :playerId, :deletedAt)',
                ['playerId' => PlayerFixture::PLAYER_ADMIN, 'deletedAt' => $deletedAt],
            );
        }

        $handler = self::getContainer()->get(PruneSolvingTimeGraveyardHandler::class);
        self::assertSame(2, $handler(new PruneSolvingTimeGraveyard(retentionMonths: 24)));

        self::assertSame(1, $this->rowsOf('solving_time_graveyard', PlayerFixture::PLAYER_ADMIN));
        self::assertSame(1, $this->rowsOf('solving_time_tombstone', PlayerFixture::PLAYER_ADMIN));
        self::assertSame('{"label": "recent"}', $this->database->fetchOne('SELECT snapshot::text FROM solving_time_graveyard'));
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function rowCount(Connection $database, string $sql, array $parameters = []): int
    {
        $value = $database->fetchOne($sql, $parameters);
        assert(is_int($value) || is_string($value));

        return (int) $value;
    }

    private function rowsOf(string $table, string $playerId): int
    {
        return self::rowCount($this->database, "SELECT COUNT(*) FROM {$table} WHERE player_id = :playerId", ['playerId' => $playerId]);
    }
}
