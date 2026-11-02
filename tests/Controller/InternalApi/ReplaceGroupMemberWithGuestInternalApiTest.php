<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * docs/features/internal-api.md, "Pairs and teams": a registered player added to a pair/team result by mistake
 * becomes the guest the tracker meant - in that one result only (TIME_12 and TIME_41 share one pair)
 */
final class ReplaceGroupMemberWithGuestInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testThePlayerBecomesAGuestOfTheirNameInThisResultOnly(): void
    {
        $browser = self::createClient();
        $pairBefore = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);

        $answer = self::callInternalApi($browser, 'POST', $this->uri(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_PRIVATE));
        self::assertResponseIsSuccessful();

        $puzzlers = $answer['puzzlers'] ?? null;
        self::assertIsArray($puzzlers);
        self::assertCount(2, $puzzlers);
        self::assertIsArray($puzzlers[0]);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $puzzlers[0]['playerId'] ?? null);
        self::assertSame(['playerId' => null, 'guestName' => 'Jane Smith'], $puzzlers[1]);

        $teamId = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);
        self::assertSame($teamId, $answer['teamId'] ?? null);
        self::assertNotSame($pairBefore, $teamId);

        /** @var list<array{player_id: null|string, guest_name: null|string}> $members */
        $members = $this->database()->fetchAllAssociative(
            'SELECT player_id, guest_name FROM puzzling_team_member WHERE team_id = :teamId ORDER BY position',
            ['teamId' => $teamId],
        );
        self::assertSame([
            ['player_id' => PlayerFixture::PLAYER_REGULAR, 'guest_name' => null],
            ['player_id' => null, 'guest_name' => 'Jane Smith'],
        ], $members);

        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $this->snapshotOf(PuzzleSolvingTimeFixture::TIME_12));

        // The other result of the pair is untouched
        self::assertSame($pairBefore, $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_41));
    }

    public function testAnotherGuestName(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', $this->uri(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_PRIVATE), ['guestName' => '  Jane   S. ']);
        self::assertResponseIsSuccessful();
        $puzzlers = $answer['puzzlers'] ?? null;
        self::assertIsArray($puzzlers);
        self::assertSame(['playerId' => null, 'guestName' => 'Jane S.'], $puzzlers[1] ?? null);
    }

    public function testTheTrackerCannotBeReplaced(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_REGULAR));
        self::assertResponseStatusCodeSame(409);
    }

    public function testSomebodyOutsideTheGroupIsAConflict(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_ADMIN));
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString(PlayerFixture::PLAYER_PRIVATE, $this->snapshotOf(PuzzleSolvingTimeFixture::TIME_12));
    }

    public function testUnknownTimeIsNotFound(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri('018d0006-0000-0000-0000-00000000ffff', PlayerFixture::PLAYER_PRIVATE));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownFieldIsRefused(): void
    {
        $browser = self::createClient();

        self::callInternalApi($browser, 'POST', $this->uri(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_PRIVATE), ['name' => 'x']);
        self::assertResponseStatusCodeSame(400);
    }

    private function uri(string $timeId, string $playerId): string
    {
        return '/internal-api/solving-times/' . $timeId . '/members/' . $playerId . '/replace-with-guest';
    }

    private function teamIdOf(string $timeId): string
    {
        $teamId = $this->database()->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($teamId);

        return $teamId;
    }

    private function snapshotOf(string $timeId): string
    {
        $team = $this->database()->fetchOne('SELECT team FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($team);

        return $team;
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
