<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetFastestPairs;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFastestPairsTest extends KernelTestCase
{
    private GetFastestPairs $query;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetFastestPairs::class);

        /** @var PlayerRepository $playerRepository */
        $playerRepository = $container->get(PlayerRepository::class);
        $this->playerRepository = $playerRepository;
    }

    public function testPerPiecesCountReturnsDuoTimes(): void
    {
        // 1000 piece puzzles have duo times in fixtures (TIME_12, TIME_41)
        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertNotEmpty($results);

        foreach ($results as $result) {
            self::assertSame(1000, $result->piecesCount);
        }
    }

    public function testPerPiecesCountReturnsEmptyForNonExistentPiecesCount(): void
    {
        $results = $this->query->perPiecesCount(42, 10, null);

        self::assertEmpty($results);
    }

    public function testPerPiecesCountRespectsLimit(): void
    {
        $results = $this->query->perPiecesCount(1000, 1, null);

        self::assertLessThanOrEqual(1, count($results));
    }

    public function testMixedTeamWithOnePublicMemberIsShown(): void
    {
        // Both fixture pairs are PLAYER_REGULAR (public) + PLAYER_PRIVATE (private):
        // bool_or(p.is_private = false) is true, so the team must appear.
        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertNotEmpty($results);
    }

    public function testFullyPrivateTeamIsHidden(): void
    {
        // Both fixture pair teams are [PLAYER_REGULAR, PLAYER_PRIVATE]. Marking
        // PLAYER_REGULAR as private leaves no public member, so the bool_or HAVING
        // clause must drop both pair entries.
        $regular = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $regular->changeProfileVisibility(isPrivate: true);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertEmpty($results);
    }

    public function testTimesWithABlockedMemberAreHiddenUnlessTheViewerTookPart(): void
    {
        // Fixture group times are PLAYER_REGULAR + PLAYER_PRIVATE
        $everyone = array_map(static fn ($r) => $r->timeId, $this->query->perPiecesCount(1000, 10, null));
        self::assertContains(PuzzleSolvingTimeFixture::TIME_12, $everyone);

        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_PRIVATE);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        $visible = array_map(static fn ($r) => $r->timeId, $this->query->perPiecesCount(1000, 10, null));
        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_12, $visible);

        // PLAYER_REGULAR blocks PLAYER_PRIVATE too (UserBlockFixture), but took part: own history stays whole
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_REGULAR);

        self::assertSame($everyone, array_map(static fn ($r) => $r->timeId, $this->query->perPiecesCount(1000, 10, null)));
    }

    public function testEachPairIsListedOnceWithItsBestTime(): void
    {
        // TIME_12 (3600 s) and TIME_41 (4000 s) are the same two people
        $timeIds = array_map(static fn ($r) => $r->timeId, $this->query->perPiecesCount(1000, 10, null));

        self::assertContains(PuzzleSolvingTimeFixture::TIME_12, $timeIds);
        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_41, $timeIds);
        self::assertCount(count(array_unique($timeIds)), $timeIds);
    }

    public function testCountryKeepsOnlyPairsWithAMemberFromThatCountry(): void
    {
        // The fixture pair is PLAYER_REGULAR (cz) + PLAYER_PRIVATE (us)
        $czech = array_map(static fn ($r) => $r->timeId, $this->query->perPiecesCount(1000, 10, CountryCode::cz));
        self::assertSame([PuzzleSolvingTimeFixture::TIME_12], $czech);

        self::assertEmpty($this->query->perPiecesCount(1000, 10, CountryCode::gb));
    }

    public function testCountryLooksBeyondTheWorldsFastestPairs(): void
    {
        // 11 faster pairs of another country fill the world's top 10 - the country must still see its own pair
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'CREATE TEMP TABLE faster_pair AS SELECT * FROM puzzle_solving_time WHERE id = :timeId',
            ['timeId' => PuzzleSolvingTimeFixture::TIME_12],
        );

        for ($i = 1; $i <= 11; $i++) {
            $teamId = Uuid::uuid7()->toString();
            $connection->executeStatement(
                "INSERT INTO puzzling_team (id, composition_key, size, created_at) VALUES (:id, substr(md5(:id) || md5(:id), 1, 40), 2, NOW())",
                ['id' => $teamId],
            );
            $connection->executeStatement(
                "INSERT INTO puzzling_team_member (id, team_id, member_key, player_id, guest_name, position) VALUES (:first, :team, :player, :player, NULL, 0), (:second, :team, :guestKey, NULL, :guest, 1)",
                [
                    'first' => Uuid::uuid7()->toString(),
                    'second' => Uuid::uuid7()->toString(),
                    'team' => $teamId,
                    'player' => PlayerFixture::PLAYER_WITH_STRIPE,
                    'guestKey' => 'g:guest ' . $i,
                    'guest' => 'Guest ' . $i,
                ],
            );
            $connection->executeStatement(
                "UPDATE faster_pair SET id = CAST(:id AS uuid), seconds_to_solve = 60, puzzling_team_id = CAST(:team AS uuid), player_id = CAST(:player AS uuid), team = json_build_object('puzzlers', json_build_array(json_build_object('player_id', CAST(:player AS text)), json_build_object('player_id', NULL, 'player_name', CAST(:guest AS text))))",
                ['id' => Uuid::uuid7()->toString(), 'team' => $teamId, 'player' => PlayerFixture::PLAYER_WITH_STRIPE, 'guest' => 'Guest ' . $i],
            );
            $connection->executeStatement('INSERT INTO puzzle_solving_time SELECT * FROM faster_pair');
        }

        self::assertNotContains(PuzzleSolvingTimeFixture::TIME_12, array_map(static fn ($r) => $r->timeId, $this->query->perPiecesCount(1000, 10, null)));
        self::assertCount(10, $this->query->perPiecesCount(1000, 10, CountryCode::gb));

        $czech = array_map(static fn ($r) => $r->timeId, $this->query->perPiecesCount(1000, 10, CountryCode::cz));
        self::assertSame([PuzzleSolvingTimeFixture::TIME_12], $czech);
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
