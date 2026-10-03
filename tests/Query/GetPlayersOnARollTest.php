<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Query\GetPlayersOnARoll;
use SpeedPuzzling\Web\Results\PlayerOnARoll;
use SpeedPuzzling\Web\Tests\ClonesSolvingTimes;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\CommunityScope;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\PlayerMomentType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * docs/features/players-page/README.md, "This week". The weeks are set on the precomputed table directly after a
 * rebuild, so the order does not depend on how many days ago the fixture times landed.
 *
 * Countries: PLAYER_REGULAR and PLAYER_ADMIN cz, PLAYER_PRIVATE us (private), PLAYER_WITH_FAVORITES de,
 * PLAYER_WITH_STRIPE gb. UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE.
 */
final class GetPlayersOnARollTest extends KernelTestCase
{
    use ClonesSolvingTimes;

    private GetPlayersOnARoll $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetPlayersOnARoll::class);
        $this->database = self::getContainer()->get(Connection::class);

        $this->recalculate();
    }

    public function testMostPuzzlesFirstThenMostPieces(): void
    {
        $this->setWeeks([
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
            PlayerFixture::PLAYER_ADMIN => [5, 4000],
            PlayerFixture::PLAYER_WITH_STRIPE => [9, 4500],
            PlayerFixture::PLAYER_WITH_FAVORITES => [1, 500],
        ]);

        $people = $this->query->forScope(CommunityScope::world(), 6);

        self::assertSame([
            PlayerFixture::PLAYER_WITH_STRIPE,
            PlayerFixture::PLAYER_ADMIN,
            PlayerFixture::PLAYER_REGULAR,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        ], self::ids($people));
        self::assertSame(9, $people[0]->puzzles);
        self::assertSame(4500, $people[0]->pieces);
        self::assertSame(CountryCode::gb, $people[0]->playerCountry);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $people[0]->playerName);
    }

    public function testTheLimitKeepsTheTop(): void
    {
        $this->setWeeks([
            PlayerFixture::PLAYER_REGULAR => [3, 1500],
            PlayerFixture::PLAYER_ADMIN => [2, 1000],
            PlayerFixture::PLAYER_WITH_STRIPE => [4, 2000],
        ]);

        self::assertSame(
            [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR],
            self::ids($this->query->forScope(CommunityScope::world(), 2)),
        );
    }

    public function testACountryListsOnlyItsOwnPeople(): void
    {
        $this->setWeeks([
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
            PlayerFixture::PLAYER_ADMIN => [6, 3000],
            PlayerFixture::PLAYER_WITH_STRIPE => [9, 4500],
            PlayerFixture::PLAYER_WITH_FAVORITES => [1, 500],
        ]);

        self::assertSame(
            [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR],
            self::ids($this->query->forScope(CommunityScope::country(CountryCode::cz), 6)),
        );
        self::assertSame(
            [PlayerFixture::PLAYER_WITH_STRIPE],
            self::ids($this->query->forScope(CommunityScope::country(CountryCode::gb), 6)),
        );
    }

    public function testNobodyWithoutAResultThisWeek(): void
    {
        $this->setWeeks([]);

        self::assertSame([], $this->query->forScope(CommunityScope::world(), 6));

        // A quiet country: nobody from France at all
        $this->setWeeks([PlayerFixture::PLAYER_REGULAR => [5, 2500]]);
        self::assertSame([], $this->query->forScope(CommunityScope::country(CountryCode::fr), 6));
    }

    public function testPrivatePlayersAreLeftOutForEverybody(): void
    {
        $this->setWeeks([
            PlayerFixture::PLAYER_PRIVATE => [20, 10000],
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
        ]);

        self::assertSame([PlayerFixture::PLAYER_REGULAR], self::ids($this->query->forScope(CommunityScope::world(), 6)));

        // Even for a viewer who may see the private profile (here: the player themselves)
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_PRIVATE);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], self::ids($this->query->forScope(CommunityScope::world(), 6)));

        $this->database->executeStatement('UPDATE player SET is_private = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);
        self::assertSame(
            [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_REGULAR],
            self::ids($this->query->forScope(CommunityScope::world(), 6)),
        );
    }

    public function testThePlayerTheViewerBlocksIsLeftOut(): void
    {
        // The fixture's blocked player is a private profile, which the list leaves out anyway
        $this->database->executeStatement('UPDATE player SET is_private = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE]);
        $this->setWeeks([
            PlayerFixture::PLAYER_PRIVATE => [20, 10000],
            PlayerFixture::PLAYER_REGULAR => [5, 2500],
        ]);

        $everyone = [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_REGULAR];
        self::assertSame($everyone, self::ids($this->query->forScope(CommunityScope::world(), 6)));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);
        self::assertSame([PlayerFixture::PLAYER_REGULAR], self::ids($this->query->forScope(CommunityScope::world(), 6)));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);
        self::assertSame($everyone, self::ids($this->query->forScope(CommunityScope::world(), 6)));
    }

    public function testAFreshPersonalBestOnFiveHundredPiecesIsTheFirstChip(): void
    {
        $previousBest = (int) self::numeric($this->database->fetchOne(
            "SELECT MIN(t.seconds_to_solve) FROM puzzle_solving_time t JOIN puzzle z ON z.id = t.puzzle_id
             WHERE t.player_id = :id AND t.puzzling_type = 'solo' AND z.pieces_count = 500 AND t.suspicious = false",
            ['id' => PlayerFixture::PLAYER_REGULAR],
        ));
        // TIME_01: PLAYER_REGULAR, PUZZLE_500_01, solo
        $this->cloneSolvingTime(PuzzleSolvingTimeFixture::TIME_01, ['seconds_to_solve' => $previousBest - 60, 'days_ago' => 0]);
        $this->recalculate();

        $regular = $this->person(PlayerFixture::PLAYER_REGULAR, $this->query->forScope(CommunityScope::world(), 6));

        self::assertNotEmpty($regular->chips);
        self::assertSame(PlayerMomentType::PersonalBest, $regular->chips[0]->type);
        self::assertSame(500, $regular->chips[0]->piecesCount);
        self::assertLessThanOrEqual(PlayerOnARoll::CHIPS, count($regular->chips));
    }

    public function testChipsAreTheMostNotableMomentsOfTheLastSevenDays(): void
    {
        $this->setWeeks([PlayerFixture::PLAYER_REGULAR => [5, 2500]]);
        $this->database->executeStatement('DELETE FROM player_moment WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        $this->addMoment(PlayerFixture::PLAYER_REGULAR, PlayerMomentType::FirstResult, 'first', daysAgo: 1);
        $this->addMoment(PlayerFixture::PLAYER_REGULAR, PlayerMomentType::PersonalBest, 'pb:300', daysAgo: 2, piecesCount: 300, value: 900);
        $this->addMoment(PlayerFixture::PLAYER_REGULAR, PlayerMomentType::PiecesMilestone, 'pieces:1000000', daysAgo: 3, value: 1_000_000);
        // Older than the week: not a chip, however notable
        $this->addMoment(PlayerFixture::PLAYER_REGULAR, PlayerMomentType::PuzzlesMilestone, 'puzzles:500', daysAgo: 8, value: 500);

        $chips = $this->person(PlayerFixture::PLAYER_REGULAR, $this->query->forScope(CommunityScope::world(), 6))->chips;

        self::assertSame(
            [[PlayerMomentType::PiecesMilestone, 1_000_000], [PlayerMomentType::PersonalBest, 900]],
            array_map(static fn ($chip): array => [$chip->type, $chip->value], $chips),
        );
        self::assertSame('1M', $chips[0]->compactValue());

        $this->addMoment(PlayerFixture::PLAYER_REGULAR, PlayerMomentType::PersonalBest, 'pb:1000', daysAgo: 6, piecesCount: 1000, value: 3600);
        $this->addMoment(PlayerFixture::PLAYER_REGULAR, PlayerMomentType::PuzzlesMilestone, 'puzzles:250', daysAgo: 4, value: 250);

        $chips = $this->person(PlayerFixture::PLAYER_REGULAR, $this->query->forScope(CommunityScope::world(), 6))->chips;

        self::assertSame(
            [[PlayerMomentType::PersonalBest, 1000], [PlayerMomentType::PuzzlesMilestone, null]],
            array_map(static fn ($chip): array => [$chip->type, $chip->piecesCount], $chips),
        );
    }

    public function testAPersonWithoutMomentsHasNoChips(): void
    {
        $this->setWeeks([PlayerFixture::PLAYER_WITH_FAVORITES => [2, 1000]]);
        $this->database->executeStatement('DELETE FROM player_moment WHERE player_id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]);

        $people = $this->query->forScope(CommunityScope::world(), 6);

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], self::ids($people));
        self::assertSame([], $people[0]->chips);
    }

    private function recalculate(): void
    {
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());
    }

    /**
     * Everybody else gets a quiet week.
     *
     * @param array<string, array{int, int}> $weeks player id => [puzzles, pieces] of the last 7 days
     */
    private function setWeeks(array $weeks): void
    {
        $this->database->executeStatement('UPDATE community_player_stats SET solves7d = 0, pieces7d = 0');

        foreach ($weeks as $playerId => [$puzzles, $pieces]) {
            $this->database->executeStatement(
                'UPDATE community_player_stats SET solves7d = :puzzles, pieces7d = :pieces WHERE player_id = :id',
                ['puzzles' => $puzzles, 'pieces' => $pieces, 'id' => $playerId],
            );
        }
    }

    private function addMoment(
        string $playerId,
        PlayerMomentType $type,
        string $dedupeKey,
        int $daysAgo,
        null|int $piecesCount = null,
        null|int $value = null,
    ): void {
        $this->database->executeStatement(
            "INSERT INTO player_moment (id, player_id, type, dedupe_key, occurred_at, solving_time_id, pieces_count, value, previous_value, detected_at)
             VALUES (:id, :player, :type, :key, :occurred, NULL, :pieces, :value, NULL, :occurred)",
            [
                'id' => Uuid::uuid7()->toString(),
                'player' => $playerId,
                'type' => $type->value,
                'key' => $dedupeKey,
                'occurred' => (new DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d H:i:s'),
                'pieces' => $piecesCount,
                'value' => $value,
            ],
        );
    }

    /**
     * @param list<PlayerOnARoll> $people
     */
    private function person(string $playerId, array $people): PlayerOnARoll
    {
        foreach ($people as $person) {
            if ($person->playerId === $playerId) {
                return $person;
            }
        }

        self::fail('Not on a roll: ' . $playerId);
    }

    /**
     * @param list<PlayerOnARoll> $people
     * @return list<string>
     */
    private static function ids(array $people): array
    {
        return array_map(static fn (PlayerOnARoll $person): string => $person->playerId, $people);
    }

    private static function numeric(mixed $value): int|float|string
    {
        self::assertIsNumeric($value);

        return $value;
    }
}
