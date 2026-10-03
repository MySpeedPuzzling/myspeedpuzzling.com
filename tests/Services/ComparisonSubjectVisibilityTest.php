<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ComparisonSubjectVisibility;
use SpeedPuzzling\Web\Services\PuzzlersGrouping;
use SpeedPuzzling\Web\Services\PuzzlingTeamResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fixtures: PLAYER_REGULAR blocks PLAYER_PRIVATE (private), who lets PLAYER_WITH_FAVORITES see her. The two are a pair
 * (TIME_12). Nobody is signed in - the service never asks the security token.
 */
final class ComparisonSubjectVisibilityTest extends KernelTestCase
{
    private ComparisonSubjectVisibility $visibility;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->visibility = self::getContainer()->get(ComparisonSubjectVisibility::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testPlayers(): void
    {
        $cases = [
            'public' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, ComparisonKind::Solo],
            'oneself, private or not' => [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_PRIVATE, ComparisonKind::Solo],
            'one the owner blocked' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE, null],
            'one who blocked the owner - like his profile, he stays' => [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_REGULAR, ComparisonKind::Solo],
            'private, not revealed' => [PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE, null],
            'private, on her allow list' => [PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE, ComparisonKind::Solo],
            'nobody' => [PlayerFixture::PLAYER_REGULAR, Uuid::uuid7()->toString(), null],
        ];

        foreach ($cases as $case => [$ownerId, $playerId, $expected]) {
            self::assertSame($expected, $this->visibility->availableKind($ownerId, ComparisonSubjectRef::player($playerId)), $case);
        }

        self::assertNull($this->visibility->availableKind('not-a-uuid', ComparisonSubjectRef::player(PlayerFixture::PLAYER_REGULAR)));
        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::player('not-a-uuid')));
    }

    public function testBeingBlockedBySomebodyDoesNotMakeThemUnavailable(): void
    {
        // PLAYER_ADMIN must not see PLAYER_WITH_STRIPE - she must not be able to tell
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame(ComparisonKind::Solo, $this->visibility->availableKind(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::player(PlayerFixture::PLAYER_ADMIN)));
        self::assertSame(ComparisonKind::Pairs, $this->visibility->availableKind(
            PlayerFixture::PLAYER_WITH_STRIPE,
            ComparisonSubjectRef::team($this->team(PlayerFixture::PLAYER_REGULAR, ['#admin'])),
        ));

        // Her own block does count
        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_ADMIN, ComparisonSubjectRef::player(PlayerFixture::PLAYER_WITH_STRIPE)));
    }

    public function testABlockOutranksTheAllowList(): void
    {
        // As PrivateProfileAccess::sqlRevealedIdsOf(): any block between the two and she is not revealed - her profile is private to him then too
        $this->block(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_WITH_FAVORITES, ComparisonSubjectRef::player(PlayerFixture::PLAYER_PRIVATE)));
    }

    public function testKindFollowsTheTeamSize(): void
    {
        $pair = $this->team(PlayerFixture::PLAYER_WITH_STRIPE, ['#admin']);
        $team = $this->team(PlayerFixture::PLAYER_WITH_STRIPE, ['#admin', 'Granny']);

        self::assertSame(ComparisonKind::Pairs, $this->visibility->availableKind(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::team($pair)));
        self::assertSame(ComparisonKind::Teams, $this->visibility->availableKind(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::team($team)));
        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::team(Uuid::uuid7()->toString())));
    }

    public function testATeamWithAMemberTheOwnerBlockedIsGoneEvenWhenTheOwnerIsInIt(): void
    {
        $pair = $this->fixturePair();

        // PLAYER_REGULAR blocks PLAYER_PRIVATE: their pair is gone for him...
        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::team($pair)));
        // ...but not for her - being blocked changes nothing
        self::assertSame(ComparisonKind::Pairs, $this->visibility->availableKind(PlayerFixture::PLAYER_PRIVATE, ComparisonSubjectRef::team($pair)));

        // Anybody else sees it - PLAYER_REGULAR is public, PLAYER_PRIVATE gets masked on the read side
        self::assertSame(ComparisonKind::Pairs, $this->visibility->availableKind(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::team($pair)));

        // A member blocking the owner changes nothing; the owner blocking a member does
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(ComparisonKind::Pairs, $this->visibility->availableKind(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::team($pair)));

        $this->block(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_REGULAR);
        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::team($pair)));
    }

    public function testATeamOfPrivatePlayersOnlyIsForItsMembersAndWhoeverTheyRevealedThemselvesTo(): void
    {
        $team = ComparisonSubjectRef::team($this->team(PlayerFixture::PLAYER_PRIVATE, ['Granny']));

        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_WITH_STRIPE, $team));
        self::assertSame(ComparisonKind::Pairs, $this->visibility->availableKind(PlayerFixture::PLAYER_WITH_FAVORITES, $team));
        self::assertSame(ComparisonKind::Pairs, $this->visibility->availableKind(PlayerFixture::PLAYER_PRIVATE, $team));

        // Two private members: one visible member is enough
        $this->database->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_ADMIN]);
        $privateTeam = ComparisonSubjectRef::team($this->team(PlayerFixture::PLAYER_PRIVATE, ['#admin', 'Granny']));

        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_WITH_STRIPE, $privateTeam));
        self::assertSame(ComparisonKind::Teams, $this->visibility->availableKind(PlayerFixture::PLAYER_WITH_FAVORITES, $privateTeam));
        self::assertSame(ComparisonKind::Teams, $this->visibility->availableKind(PlayerFixture::PLAYER_ADMIN, $privateTeam));
    }

    public function testATeamOfGuestsIsNobodysSubject(): void
    {
        $teamId = $this->team(PlayerFixture::PLAYER_WITH_STRIPE, ['Granny']);
        $this->database->executeStatement(
            "UPDATE puzzling_team_member SET player_id = NULL, guest_name = 'Sarah', member_key = 'g:sarah' WHERE team_id = :teamId AND player_id IS NOT NULL",
            ['teamId' => $teamId],
        );

        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectRef::team($teamId)));
        self::assertNull($this->visibility->availableKind(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectRef::team($teamId)));
    }

    private function fixturePair(): string
    {
        $teamId = $this->database->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($teamId);

        return $teamId;
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'admin')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }

    /**
     * @param list<string> $others "#code" or a guest name, like the add-time form
     */
    private function team(string $playerId, array $others): string
    {
        $container = self::getContainer();
        $player = $container->get(PlayerRepository::class)->get($playerId);
        $group = $container->get(PuzzlersGrouping::class)->assembleGroup($player, $others);
        $team = $container->get(PuzzlingTeamResolver::class)->resolve($group);
        assert($team !== null);

        return $team->id->toString();
    }
}
