<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetFeatureRequestVoters;
use SpeedPuzzling\Web\Tests\DataFixtures\FeatureRequestFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFeatureRequestVotersTest extends KernelTestCase
{
    private GetFeatureRequestVoters $query;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetFeatureRequestVoters::class);
        $this->connection = $container->get(Connection::class);
    }

    public function testReturnsAllVotersExcludingTheSpecifiedPlayer(): void
    {
        // FEATURE_REQUEST_POPULAR: voters are ADMIN + REGULAR. Author = PLAYER_WITH_STRIPE.
        $voters = $this->query->excludingPlayer(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            excludedPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        );

        $playerIds = array_map(fn($v) => $v->playerId, $voters);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
        self::assertNotContains(PlayerFixture::PLAYER_WITH_STRIPE, $playerIds);
        self::assertCount(2, $voters);
    }

    public function testExcludesVoterMatchingExcludedPlayer(): void
    {
        $voters = $this->query->excludingPlayer(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            excludedPlayerId: PlayerFixture::PLAYER_ADMIN,
        );

        $playerIds = array_map(fn($v) => $v->playerId, $voters);
        self::assertNotContains(PlayerFixture::PLAYER_ADMIN, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
        self::assertCount(1, $voters);
    }

    public function testSkipsVotersWithNullEmail(): void
    {
        $this->connection->executeStatement(
            'UPDATE player SET email = NULL WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );

        $voters = $this->query->excludingPlayer(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            excludedPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        );

        $playerIds = array_map(fn($v) => $v->playerId, $voters);
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $playerIds);
        self::assertCount(1, $voters);
    }

    public function testReturnsEmptyArrayWhenNoVoters(): void
    {
        // FEATURE_REQUEST_NEW has no votes.
        $voters = $this->query->excludingPlayer(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
            excludedPlayerId: PlayerFixture::PLAYER_ADMIN,
        );

        self::assertSame([], $voters);
    }

    public function testDoesNotLeakVotersFromOtherFeatureRequests(): void
    {
        // Query a feature request different from POPULAR; must only return its voters (none here).
        $voters = $this->query->excludingPlayer(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_NEW,
            excludedPlayerId: PlayerFixture::PLAYER_ADMIN,
        );

        self::assertCount(0, $voters);
    }

    public function testIncludesEmailAndLocaleInResult(): void
    {
        $this->connection->executeStatement(
            'UPDATE player SET locale = :locale WHERE id = :id',
            ['locale' => 'de', 'id' => PlayerFixture::PLAYER_ADMIN],
        );

        $voters = $this->query->excludingPlayer(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            excludedPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        );

        $admin = null;
        foreach ($voters as $voter) {
            if ($voter->playerId === PlayerFixture::PLAYER_ADMIN) {
                $admin = $voter;
                break;
            }
        }

        self::assertNotNull($admin);
        self::assertSame('admin@speedpuzzling.cz', $admin->email);
        self::assertSame('de', $admin->locale);
    }

    public function testDistinctVoterIdCollapsesRepeatedVotesOnSameRequest(): void
    {
        // Migration Version20260324082438 dropped UNIQUE (feature_request_id, voter_id),
        // so a player can legitimately have multiple vote rows for the same request.
        // The query must collapse them so that recipient lists never contain duplicates.
        $this->connection->executeStatement(
            'INSERT INTO feature_request_vote (id, feature_request_id, voter_id, voted_at) '
            . 'VALUES (:id, :featureRequestId, :voterId, NOW())',
            [
                'id' => Uuid::uuid7()->toString(),
                'featureRequestId' => FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
                'voterId' => PlayerFixture::PLAYER_ADMIN,
            ],
        );

        $voters = $this->query->excludingPlayer(
            featureRequestId: FeatureRequestFixture::FEATURE_REQUEST_POPULAR,
            excludedPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
        );

        $adminCount = count(array_filter(
            $voters,
            fn($v) => $v->playerId === PlayerFixture::PLAYER_ADMIN,
        ));
        self::assertSame(1, $adminCount, 'Admin must appear exactly once even with repeated vote rows');
    }
}
