<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Utils\Json;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Query\GetSubscribedPlayers;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetSubscribedPlayersTest extends KernelTestCase
{
    /**
     * The query before it became index-backed (fb0396d0). Kept only here, as the reference
     * the current one must return exactly the same players as.
     */
    private const string PREVIOUS_QUERY = <<<SQL
SELECT DISTINCT p.id
FROM player p
JOIN LATERAL json_array_elements_text(p.favorite_players) as fav(uuid)
ON fav.uuid IN (:playerIds)
SQL;

    private GetSubscribedPlayers $query;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var GetSubscribedPlayers $query */
        $query = $container->get(GetSubscribedPlayers::class);
        $this->query = $query;

        /** @var Connection $database */
        $database = $container->get(Connection::class);
        $this->database = $database;

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager = $entityManager;
    }

    public function testReturnsExactlyWhatThePreviousQueryReturned(): void
    {
        [$unfollowed, $followedOnce, $teammateA, $teammateB, $popular, $selfFollower] = $this->createPlayers(6);
        [$soleFollower, $teamFollower, $fan1, $fan2, $fan3, $duplicateFollower, $emptyFollower, $upperCaseFollower] = $this->createPlayers(8);

        $this->setFavorites($soleFollower, [$followedOnce]);
        // Follows two members of the same team - one row, not two
        $this->setFavorites($teamFollower, [$teammateA, $teammateB, $popular]);
        $this->setFavorites($fan1, [$popular]);
        $this->setFavorites($fan2, [$popular, $teammateA]);
        $this->setFavorites($fan3, [PlayerFixture::PLAYER_REGULAR, $popular]);
        // Shapes the entity never writes (it refuses duplicates and following yourself), but the column allows
        $this->setFavorites($duplicateFollower, [$followedOnce, $followedOnce]);
        $this->setFavorites($selfFollower, [$selfFollower]);
        $this->setFavorites($emptyFollower, []);
        // Ids are compared as case-sensitive text, in both forms
        $this->setFavorites($upperCaseFollower, [strtoupper($followedOnce)]);

        $calls = [
            'followed by nobody' => [[$unfollowed], []],
            'one follower, plus one listing the id twice' => [[$followedOnce], [$soleFollower, $duplicateFollower]],
            'several followers' => [[$popular], [$teamFollower, $fan1, $fan2, $fan3]],
            'follower of two team members is returned once' => [[$teammateA, $teammateB], [$teamFollower, $fan2]],
            'follows themself' => [[$selfFollower], [$selfFollower]],
            'fixture favorites' => [[PlayerFixture::PLAYER_REGULAR], [PlayerFixture::PLAYER_WITH_FAVORITES, $fan3]],
            'the same id passed twice' => [[$popular, $popular], [$teamFollower, $fan1, $fan2, $fan3]],
            'unknown id' => [[Uuid::uuid7()->toString()], []],
            'everything at once' => [
                [$unfollowed, $followedOnce, $teammateA, $teammateB, $popular, $selfFollower, PlayerFixture::PLAYER_ADMIN],
                [$soleFollower, $duplicateFollower, $teamFollower, $fan1, $fan2, $fan3, $selfFollower, PlayerFixture::PLAYER_WITH_FAVORITES],
            ],
        ];

        foreach ($calls as $label => [$playerIds, $expectedFollowers]) {
            $current = $this->query->ofPlayers($playerIds);
            $previous = $this->previousQuery($playerIds);

            self::assertSame($this->sorted($previous), $this->sorted($current), $label . ': same players as the previous query');
            self::assertSame($this->sorted($expectedFollowers), $this->sorted($current), $label);
            self::assertSame(array_values(array_unique($current)), $current, $label . ': each follower once');
        }
    }

    public function testEmptyListQueriesNothing(): void
    {
        self::assertSame([], $this->query->ofPlayers([]));
    }

    /**
     * NULL cannot be stored (the column is NOT NULL) and the entity only ever writes a list of
     * id strings, so these shapes are checked on the operator itself: both forms agree on NULL and
     * on arrays holding anything else. (Non-array JSON would make the previous form raise an error.)
     */
    public function testOperatorAgreesWithThePreviousFormOnEveryArrayShapeAndNull(): void
    {
        $id = Uuid::uuid7()->toString();

        $shapes = [
            'null' => null,
            'empty' => '[]',
            'match' => Json::encode([$id]),
            'duplicate match' => Json::encode([$id, $id]),
            'other id' => Json::encode([Uuid::uuid7()->toString()]),
            'upper case' => Json::encode([strtoupper($id)]),
            'json null element' => '[null]',
            'number element' => '[1]',
            'nested array' => Json::encode([[$id]]),
            'nested object' => Json::encode([[$id => true]]),
        ];

        foreach ($shapes as $label => $favorites) {
            /** @var array{previous: bool, current: bool} $row */
            $row = $this->database->fetchAssociative(
                <<<SQL
SELECT
    EXISTS (
        SELECT 1 FROM json_array_elements_text(CAST(:favorites AS json)) AS fav(uuid) WHERE fav.uuid IN (:playerIds)
    ) AS previous,
    COALESCE(CAST(:favorites AS json)::jsonb ??| ARRAY[:playerIds]::text[], false) AS current
SQL,
                ['favorites' => $favorites, 'playerIds' => [$id]],
                ['playerIds' => ArrayParameterType::STRING],
            );

            self::assertSame($row['previous'], $row['current'], $label);
        }
    }

    public function testLookupCanUseTheFavoritesIndex(): void
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $this->query->ofPlayers([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN]);

        /** @var list<array{sql: string, params: array<mixed>}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        self::assertCount(1, $executed);

        // The test database is tiny, so the planner would scan it anyway - only prove the index is usable
        $this->database->executeStatement('SET LOCAL enable_seqscan = off');
        /** @var list<string> $plan */
        $plan = $this->database->fetchFirstColumn('EXPLAIN ' . $executed[0]['sql'], array_values($executed[0]['params']));

        self::assertStringContainsString('custom_player_favorite_players_gin', implode("\n", $plan));
    }

    /**
     * @param array<string> $playerIds
     * @return list<string>
     */
    private function previousQuery(array $playerIds): array
    {
        /** @var list<string> $rows */
        $rows = $this->database
            ->executeQuery(self::PREVIOUS_QUERY, ['playerIds' => $playerIds], ['playerIds' => ArrayParameterType::STRING])
            ->fetchFirstColumn();

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function createPlayers(int $count): array
    {
        $ids = [];

        for ($i = 0; $i < $count; $i++) {
            $id = Uuid::uuid7();
            $this->entityManager->persist(new Player(
                id: $id,
                code: 'sub' . substr(str_replace('-', '', $id->toString()), -12),
                userId: null,
                email: null,
                name: 'Subscriber test ' . $i,
                registeredAt: new DateTimeImmutable(),
            ));
            $ids[] = $id->toString();
        }

        $this->entityManager->flush();
        $this->entityManager->clear();

        return $ids;
    }

    /**
     * @param list<string> $favorites
     */
    private function setFavorites(string $playerId, array $favorites): void
    {
        $this->database->executeStatement(
            'UPDATE player SET favorite_players = CAST(:favorites AS json) WHERE id = :id',
            ['favorites' => Json::encode($favorites), 'id' => $playerId],
        );
    }

    /**
     * @param array<string> $ids
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
