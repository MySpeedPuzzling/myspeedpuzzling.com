<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetFastestGroups;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetFastestGroupsTest extends KernelTestCase
{
    private GetFastestGroups $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetFastestGroups::class);
    }

    public function testPerPiecesCountDoesNotFail(): void
    {
        // No team (3+ puzzlers) fixtures exist, but the query should execute without SQL error
        $results = $this->query->perPiecesCount(1000, 10, null);

        self::assertEmpty($results);
    }

    public function testPerPiecesCountReturnsEmptyForNonExistentPiecesCount(): void
    {
        $results = $this->query->perPiecesCount(42, 10, null);

        self::assertEmpty($results);
    }

    public function testTimesWithABlockedMemberAreHiddenUnlessTheViewerTookPart(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle_solving_time SET puzzling_type = 'team' WHERE id = :id",
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );

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

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
