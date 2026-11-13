<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Doctrine\PuzzlersGroupDoctrineType;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Events\EventResultSlotClaimed;
use SpeedPuzzling\Web\Exceptions\EventResultClaimLimitReached;
use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\ReleaseEventResultSlot;
use SpeedPuzzling\Web\Message\RemoveEventResultClaim;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenEventResultSlotClaimed;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TrackerlessTimeSeeding;
use SpeedPuzzling\Web\Value\EventResultsImport\EventResultClaimRefusal;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\TeamComposition;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Claiming a spot of an imported event result, releasing it and "Not on our team"
 * (docs/features/event-results-import/README.md "Claiming a spot", "Gates"). Made-up events and teams on a puzzle the
 * fixtures have no results of.
 */
final class EventResultClaimTest extends KernelTestCase
{
    use TrackerlessTimeSeeding;

    private const string PUZZLE = PuzzleFixture::PUZZLE_1000_04;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testTheFirstClaimTurnsTheFirstSlotIntoThePlayerWhoTracksTheResultFromNowOn(): void
    {
        $time = $this->seedTeam('Puzzle Pals', 3600);
        $timeId = $time->id->toString();
        $teamId = $this->teamOf($timeId);
        $slots = $this->slotsOf($timeId);

        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR);

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));

        // The first slot is the player, the other three stay anonymous - the snapshot exactly as the Doctrine type writes it
        $snapshot = $this->rawSnapshot($timeId);
        self::assertSame($this->snapshotAsDoctrineWritesIt($timeId), $snapshot);
        $puzzlers = $this->puzzlersOf($timeId);
        self::assertSame(['player_id' => PlayerFixture::PLAYER_REGULAR, 'player_name' => null], $puzzlers[0]);
        self::assertSame(array_slice($slots, 1), array_map(static fn (array $puzzler): mixed => $puzzler['anonymous_slot'] ?? null, array_slice($puzzlers, 1)));

        // The team in place: its member and its key say the same
        self::assertSame($teamId, $this->teamOf($timeId));
        self::assertSame(
            TeamComposition::keyFromMemberKeys([strtolower(PlayerFixture::PLAYER_REGULAR), ...array_slice($slots, 1)]),
            $this->database->fetchOne('SELECT composition_key FROM puzzling_team WHERE id = :id', ['id' => $teamId]),
        );
        self::assertSame(
            PlayerFixture::PLAYER_REGULAR,
            $this->database->fetchOne('SELECT player_id FROM puzzling_team_member WHERE team_id = :id AND member_key = :key', ['id' => $teamId, 'key' => strtolower(PlayerFixture::PLAYER_REGULAR)]),
        );
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzling_team_member WHERE member_key = :slot', ['slot' => $slots[0]]));

        self::assertSame(
            [['action' => 'claimed', 'slot_key' => $slots[0], 'player_id' => PlayerFixture::PLAYER_REGULAR, 'player_name' => PlayerFixture::PLAYER_REGULAR_NAME, 'by_player_id' => PlayerFixture::PLAYER_REGULAR]],
            $this->database->fetchAllAssociative('SELECT action, slot_key, player_id, player_name, by_player_id FROM result_claim_log WHERE solving_time_id = :id', ['id' => $timeId]),
        );

        // Nobody else claimed it - nobody to tell
        $this->notifyAboutClaim($timeId, PlayerFixture::PLAYER_REGULAR);
        self::assertSame(0, $this->notificationCount('EventResultClaimed', $timeId));
    }

    public function testALaterClaimerTakesTheNextSlotTheTrackerStaysAndTheOthersAreTold(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $slots = $this->slotsOf($timeId);

        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR);
        $this->claim($timeId, PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        $puzzlers = $this->puzzlersOf($timeId);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $puzzlers[1]['player_id']);
        self::assertSame($slots[2], $puzzlers[2]['anonymous_slot'] ?? null);
        self::assertSame($this->snapshotAsDoctrineWritesIt($timeId), $this->rawSnapshot($timeId));

        $this->notifyAboutClaim($timeId, PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(
            [['player_id' => PlayerFixture::PLAYER_REGULAR, 'actor_player_id' => PlayerFixture::PLAYER_WITH_FAVORITES]],
            $this->database->fetchAllAssociative("SELECT player_id, actor_player_id FROM notification WHERE type = 'EventResultClaimed' AND target_solving_time_id = :id", ['id' => $timeId]),
        );
    }

    public function testOneSpotPerPersonPerRound(): void
    {
        $round = $this->seedTrackerlessEvent(self::PUZZLE);
        $pals = $this->seedTrackerlessTime($round, self::PUZZLE, 'Puzzle Pals', 3600)->id->toString();
        $crew = $this->seedTrackerlessTime($round, self::PUZZLE, 'Corner Crew', 3000)->id->toString();

        $this->claim($pals, PlayerFixture::PLAYER_REGULAR);

        $this->assertRefused(EventResultClaimRefusal::AlreadyInRound, fn () => $this->claim($crew, PlayerFixture::PLAYER_REGULAR));
        $this->assertRefused(EventResultClaimRefusal::AlreadyInRound, fn () => $this->claim($pals, PlayerFixture::PLAYER_REGULAR));
        self::assertSame(4, $this->anonymousCountOf($crew));
    }

    public function testAFullTeamTakesNobodyMore(): void
    {
        $pair = $this->seedTeam('Edge Twins', 1500, size: 2, category: RoundCategory::Duo)->id->toString();

        $this->claim($pair, PlayerFixture::PLAYER_REGULAR);
        $this->claim($pair, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->assertRefused(EventResultClaimRefusal::TeamFull, fn () => $this->claim($pair, PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAnOrdinaryResultIsNeverClaimed(): void
    {
        $this->assertRefused(EventResultClaimRefusal::NotImported, fn () => $this->claim(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    public function testAPlayerBlockedFromClaimingCannotClaim(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $player = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($player);
        $player->blockEventClaims(TestClock::now());
        $this->entityManager->flush();

        $this->assertRefused(EventResultClaimRefusal::Blocked, fn () => $this->claim($timeId, PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame(4, $this->anonymousCountOf($timeId));
    }

    public function testTheFirstClaimerMarksTheFirstTry(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();

        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR, firstTry: true);

        self::assertTrue($this->database->fetchOne('SELECT first_attempt FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    public function testALaterClaimerWithoutTheFirstTryTakesTheTagOff(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();

        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR, firstTry: true);
        $this->claim($timeId, PlayerFixture::PLAYER_WITH_FAVORITES);

        // A pair's/team's result is a first try only if it is everybody's
        self::assertFalse($this->database->fetchOne('SELECT first_attempt FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    public function testAFirstTryIsRefusedWhenThePlayerAlreadyHasOneAndNothingChanges(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $this->addOwnTime(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, firstAttempt: true, daysAfterEvent: 5);

        $this->assertRefused(EventResultClaimRefusal::FirstTryTaken, fn () => $this->claim($timeId, PlayerFixture::PLAYER_WITH_STRIPE, firstTry: true));

        self::assertSame(4, $this->anonymousCountOf($timeId));
        self::assertNull($this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        self::assertSame(0, self::int($this->database->fetchOne('SELECT COUNT(*) FROM result_claim_log WHERE solving_time_id = :id', ['id' => $timeId])));

        // Without the tag it is fine
        $this->claim($timeId, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertSame(3, $this->anonymousCountOf($timeId));
    }

    public function testTheSameResultIsDeletedAndTheEventsResultStays(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $ownTimeId = $this->addOwnTime(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_WITH_STRIPE_USER_ID, firstAttempt: true, daysAfterEvent: 0);

        // Their own copy carried the first try - it goes, so it no longer holds the tag
        $this->claim($timeId, PlayerFixture::PLAYER_WITH_STRIPE, firstTry: true, sameResultTimeId: $ownTimeId);

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $ownTimeId]));
        self::assertSame('event_result_claim', $this->database->fetchOne('SELECT deleted_via FROM solving_time_graveyard WHERE solving_time_id = :id', ['id' => $ownTimeId]));
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        self::assertTrue($this->database->fetchOne('SELECT first_attempt FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    public function testTheSameResultMustBeThePlayersOwnResultOfThePuzzle(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $somebodyElses = $this->addOwnTime(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID, firstAttempt: false, daysAfterEvent: 0);

        $this->assertRefused(EventResultClaimRefusal::SameResultInvalid, fn () => $this->claim($timeId, PlayerFixture::PLAYER_WITH_STRIPE, sameResultTimeId: $somebodyElses));
        // Another puzzle's result is not the same result either
        $this->assertRefused(EventResultClaimRefusal::SameResultInvalid, fn () => $this->claim($timeId, PlayerFixture::PLAYER_REGULAR, sameResultTimeId: PuzzleSolvingTimeFixture::TIME_12));

        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $somebodyElses]));
        self::assertSame(4, $this->anonymousCountOf($timeId));
    }

    public function testReleasingMovesTheTrackerToTheNextClaimerAndTheSpotIsAnonymousAgain(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $teamId = $this->teamOf($timeId);
        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR);
        $this->claim($timeId, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->messageBus->dispatch(new ReleaseEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR));

        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
        $puzzlers = $this->puzzlersOf($timeId);
        self::assertNull($puzzlers[0]['player_id']);
        self::assertIsString($puzzlers[0]['anonymous_slot'] ?? null);
        self::assertStringStartsWith(TeamComposition::ANONYMOUS_PREFIX, $puzzlers[0]['anonymous_slot']);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $puzzlers[1]['player_id']);
        self::assertSame($this->snapshotAsDoctrineWritesIt($timeId), $this->rawSnapshot($timeId));
        self::assertSame($teamId, $this->teamOf($timeId), 'The team had no other result - changed in place');
        self::assertSame(3, $this->anonymousCountOf($timeId));

        self::assertSame(
            ['released', PlayerFixture::PLAYER_REGULAR],
            ($this->database->fetchNumeric("SELECT action, by_player_id FROM result_claim_log WHERE solving_time_id = :id AND player_id = :player AND action <> 'claimed'", ['id' => $timeId, 'player' => PlayerFixture::PLAYER_REGULAR]) ?: []),
        );

        // The released player may claim it again
        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR);
        self::assertSame(2, $this->anonymousCountOf($timeId));
    }

    public function testTheLastClaimerReleasingLeavesTheResultNobodysWithoutAFirstTry(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR, firstTry: true);

        $this->messageBus->dispatch(new ReleaseEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR));

        $row = $this->database->fetchAssociative('SELECT player_id, first_attempt FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertSame(['player_id' => null, 'first_attempt' => false], $row);
        self::assertSame(4, $this->anonymousCountOf($timeId));
    }

    public function testReleasingASpotThePlayerDoesNotHoldIsRefused(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();

        $this->assertRefused(EventResultClaimRefusal::NotAClaimer, fn () => $this->messageBus->dispatch(new ReleaseEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR)));
    }

    public function testAnotherClaimerRemovesAWrongClaimAndTheRemovedPlayerIsTold(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR);
        $this->claim($timeId, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->messageBus->dispatch(new RemoveEventResultClaim($timeId, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR, 'Never met them'));

        self::assertSame(3, $this->anonymousCountOf($timeId));
        self::assertSame(
            [['action' => 'removed', 'by_player_id' => PlayerFixture::PLAYER_REGULAR, 'reason' => 'Never met them']],
            $this->database->fetchAllAssociative("SELECT action, by_player_id, reason FROM result_claim_log WHERE solving_time_id = :id AND player_id = :player AND action = 'removed'", ['id' => $timeId, 'player' => PlayerFixture::PLAYER_WITH_FAVORITES]),
        );
        self::assertSame(
            [['player_id' => PlayerFixture::PLAYER_WITH_FAVORITES]],
            $this->database->fetchAllAssociative("SELECT player_id FROM notification WHERE type = 'EventResultClaimRemoved' AND target_solving_time_id = :id", ['id' => $timeId]),
        );
        // Removed once - still allowed to claim
        self::assertNull($this->database->fetchOne('SELECT event_claims_blocked_at FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]));
    }

    public function testOnlyAnotherClaimerTheOrganizersTeamOrAnAdminRemoves(): void
    {
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();
        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR);

        // A stranger
        $this->assertRefused(EventResultClaimRefusal::NotAllowed, fn () => $this->messageBus->dispatch(new RemoveEventResultClaim($timeId, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE)));
        // The player themselves - that is "Remove from my profile"
        $this->assertRefused(EventResultClaimRefusal::NotAllowed, fn () => $this->messageBus->dispatch(new RemoveEventResultClaim($timeId, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_REGULAR)));
        self::assertSame(3, $this->anonymousCountOf($timeId));

        // An admin
        $this->messageBus->dispatch(new RemoveEventResultClaim($timeId, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN));
        self::assertSame(4, $this->anonymousCountOf($timeId));
        self::assertNull($this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]));
    }

    public function testThePlayersOwnEventsTeamRemoves(): void
    {
        $time = $this->seedTeam('Puzzle Pals', 3600);
        $timeId = $time->id->toString();
        $this->claim($timeId, PlayerFixture::PLAYER_REGULAR);

        // The event's creator manages it (GetCompetitionPermissions)
        $this->database->executeStatement('UPDATE competition SET added_by_player_id = :player WHERE id = :id', ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'id' => $time->competition?->id->toString()]);

        $this->messageBus->dispatch(new RemoveEventResultClaim($timeId, PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE));
        self::assertSame(4, $this->anonymousCountOf($timeId));
    }

    public function testAPlayerRemovedTwiceCanNoLongerClaim(): void
    {
        $round = $this->seedTrackerlessEvent(self::PUZZLE);
        $pals = $this->seedTrackerlessTime($round, self::PUZZLE, 'Puzzle Pals', 3600)->id->toString();
        $crew = $this->seedTrackerlessTime($round, self::PUZZLE, 'Corner Crew', 3000)->id->toString();

        $this->claim($pals, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->claim($pals, PlayerFixture::PLAYER_REGULAR);
        $this->messageBus->dispatch(new RemoveEventResultClaim($pals, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR));

        $this->claim($crew, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->messageBus->dispatch(new RemoveEventResultClaim($crew, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_ADMIN));

        self::assertNotNull($this->database->fetchOne('SELECT event_claims_blocked_at FROM player WHERE id = :id', ['id' => PlayerFixture::PLAYER_WITH_FAVORITES]));
        $this->assertRefused(EventResultClaimRefusal::Blocked, fn () => $this->claim($crew, PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    public function testAFullyClaimedPairJoinsThePairTheyAlreadyAreAndLeavesItAgainAlone(): void
    {
        // TIME_12: the pair PLAYER_REGULAR + PLAYER_PRIVATE, with their own results
        $existingTeamId = $this->teamOf(PuzzleSolvingTimeFixture::TIME_12);
        $existingKey = $this->database->fetchOne('SELECT composition_key FROM puzzling_team WHERE id = :id', ['id' => $existingTeamId]);

        $pair = $this->seedTeam('Edge Twins', 1500, size: 2, category: RoundCategory::Duo)->id->toString();
        $anonymousTeamId = $this->teamOf($pair);

        $this->claim($pair, PlayerFixture::PLAYER_REGULAR);
        $this->claim($pair, PlayerFixture::PLAYER_PRIVATE);

        self::assertSame($existingTeamId, $this->teamOf($pair), 'The same two people are the same pair');
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzling_team WHERE id = :id', ['id' => $anonymousTeamId]));
        self::assertSame($this->snapshotAsDoctrineWritesIt($pair), $this->rawSnapshot($pair));

        // One of them takes it off their profile: the pair's other results keep the pair, this one gets a team of its own
        $this->messageBus->dispatch(new ReleaseEventResultSlot($pair, PlayerFixture::PLAYER_PRIVATE));

        $ownTeamId = $this->teamOf($pair);
        self::assertNotSame($existingTeamId, $ownTeamId);
        self::assertSame($existingTeamId, $this->teamOf(PuzzleSolvingTimeFixture::TIME_12));
        self::assertSame($existingKey, $this->database->fetchOne('SELECT composition_key FROM puzzling_team WHERE id = :id', ['id' => $existingTeamId]));

        $members = $this->database->fetchAllAssociative('SELECT member_key, player_id FROM puzzling_team_member WHERE team_id = :id ORDER BY position', ['id' => $ownTeamId]);
        self::assertCount(2, $members);
        $memberPlayers = array_column($members, 'player_id');
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $memberPlayers);
        self::assertContains(null, $memberPlayers);
        self::assertSame(1, $this->anonymousCountOf($pair));
        self::assertSame(
            TeamComposition::keyFromMemberKeys(array_map(static fn (mixed $key): string => is_string($key) ? $key : '', array_column($members, 'member_key'))),
            $this->database->fetchOne('SELECT composition_key FROM puzzling_team WHERE id = :id', ['id' => $ownTeamId]),
        );
    }

    public function testClaimsStopAtTheDailyLimit(): void
    {
        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = self::getContainer()->get('limiter.event_result_claim');
        $timeId = $this->seedTeam('Puzzle Pals', 3600)->id->toString();

        try {
            $limiter->create(PlayerFixture::PLAYER_WITH_STRIPE)->consume($limiter->create(PlayerFixture::PLAYER_WITH_STRIPE)->consume(0)->getRemainingTokens());

            try {
                $this->claim($timeId, PlayerFixture::PLAYER_WITH_STRIPE);
                self::fail('Over the limit the claim must be refused');
            } catch (EventResultClaimLimitReached) {
            }

            self::assertSame(4, $this->anonymousCountOf($timeId));
        } finally {
            $limiter->create(PlayerFixture::PLAYER_WITH_STRIPE)->reset();
        }
    }

    private function seedTeam(string $name, int $seconds, int $size = 4, RoundCategory $category = RoundCategory::Team): PuzzleSolvingTime
    {
        $round = $this->seedTrackerlessEvent(self::PUZZLE, category: $category);

        return $this->seedTrackerlessTime($round, self::PUZZLE, $name, $seconds, $size);
    }

    private function claim(string $timeId, string $playerId, bool $firstTry = false, null|string $sameResultTimeId = null): void
    {
        $this->messageBus->dispatch(new ClaimEventResultSlot($timeId, $playerId, $firstTry, $sameResultTimeId));
    }

    private function notifyAboutClaim(string $timeId, string $playerId): void
    {
        self::getContainer()->get(NotifyWhenEventResultSlotClaimed::class)(new EventResultSlotClaimed(Uuid::fromString($timeId), Uuid::fromString($playerId)));
        $this->entityManager->flush();
    }

    /**
     * @param callable(): mixed $action
     */
    private function assertRefused(EventResultClaimRefusal $refusal, callable $action): void
    {
        try {
            $action();
            self::fail(sprintf('Expected the refusal "%s".', $refusal->value));
        } catch (EventResultClaimRefused $refused) {
            self::assertSame($refusal, $refused->refusal);
        }

        $this->entityManager->clear();
    }

    /**
     * The player's own result of the puzzle, saved through the add form's message, on the event day + $daysAfterEvent.
     */
    private function addOwnTime(string $playerId, string $userId, bool $firstAttempt, int $daysAfterEvent): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: self::PUZZLE,
            competitionId: null,
            time: '01:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: TestClock::now()->modify('-20 days')->modify(sprintf('+%d days', $daysAfterEvent))->setTime(0, 0),
            firstAttempt: $firstAttempt,
            unboxed: false,
        ));

        self::assertSame($playerId, $this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId->toString()]));

        return $timeId->toString();
    }

    private function teamOf(string $timeId): string
    {
        $teamId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($teamId);

        return $teamId;
    }

    /**
     * @return list<string>
     */
    private function slotsOf(string $timeId): array
    {
        return array_values(array_filter(array_map(static fn (array $puzzler): mixed => $puzzler['anonymous_slot'] ?? null, $this->puzzlersOf($timeId)), 'is_string'));
    }

    private function anonymousCountOf(string $timeId): int
    {
        return count($this->slotsOf($timeId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function puzzlersOf(string $timeId): array
    {
        /** @var array{puzzlers: list<array<string, mixed>>} $snapshot */
        $snapshot = json_decode($this->rawSnapshot($timeId), true, flags: JSON_THROW_ON_ERROR);

        return $snapshot['puzzlers'];
    }

    private function rawSnapshot(string $timeId): string
    {
        $json = $this->database->fetchOne('SELECT team FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertIsString($json);

        return $json;
    }

    /**
     * The stored snapshot read through the entity and written back by PuzzlersGroupDoctrineType - byte for byte what
     * an ordinary save would store.
     */
    private function snapshotAsDoctrineWritesIt(string $timeId): string
    {
        $this->entityManager->clear();
        $time = $this->entityManager->find(PuzzleSolvingTime::class, $timeId);
        self::assertNotNull($time);

        $json = Type::getType(PuzzlersGroupDoctrineType::NAME)->convertToDatabaseValue($time->team, $this->database->getDatabasePlatform());
        self::assertIsString($json);

        return $json;
    }

    private static function int(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_numeric($value));

        return (int) $value;
    }

    private function notificationCount(string $type, string $timeId): int
    {
        return self::int($this->database->fetchOne('SELECT COUNT(*) FROM notification WHERE type = :type AND target_solving_time_id = :id', ['type' => $type, 'id' => $timeId]));
    }
}
