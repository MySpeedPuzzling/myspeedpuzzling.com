<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\RoundIsNoCandidateOfSolvingTime;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultNeedsTimeLimit;
use SpeedPuzzling\Web\Exceptions\UnfinishedResultRoundNotIdentified;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddUnfinishedPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ChooseSolvingTimeRound;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Services\UnfinishedResults\UnfinishedResultHint;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\SolvingTimeSource;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Package S3 of docs/features/competitions-management/shared-puzzles-and-divisions.md (§2.4 rows 3-7 and 16): a round
 * named for a time - "Add to my profile", the API's round id, the player's pick - is kept while it is a candidate, for a
 * finished and an unfinished result, through an edit; an unfinished result whose round cannot be told is refused with
 * its own reason; the player (or a registered teammate) places an unlinked time with ChooseSolvingTimeRound.
 *
 * A made-up public event on 10 Oct 2026 (Prague): Morning (solo, 09:00 UTC) and Evening (solo, 17:00 UTC) share puzzle P,
 * Teams A and Teams B (team) share it too; every round has a 120 min limit. OWNER is entered in Evening only. Puzzles
 * shared by rounds of one category are inserted directly (the handlers refuse them until package S2).
 */
final class SharedPuzzleExplicitRoundTest extends KernelTestCase
{
    private const string COMPETITION = '019a8100-0000-7000-8000-000000000001';
    private const string MORNING = '019a8100-0000-7000-8000-000000000011';
    private const string EVENING = '019a8100-0000-7000-8000-000000000012';
    private const string TEAMS_A = '019a8100-0000-7000-8000-000000000013';
    private const string TEAMS_B = '019a8100-0000-7000-8000-000000000014';

    private const string P = PuzzleFixture::PUZZLE_500_04;

    private const string OWNER = PlayerFixture::PLAYER_REGULAR;
    private const string OWNER_USER = PlayerFixture::PLAYER_REGULAR_USER_ID;
    private const string TEAMMATE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string OTHER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string OTHER_USER = PlayerFixture::PLAYER_WITH_STRIPE_USER_ID;

    private const string DAY = '2026-10-10';

    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);

        $this->database->executeStatement(
            "INSERT INTO competition (id, name, location_country_code, is_online, approved_at) VALUES (:id, 'Lantern Open', 'cz', false, '2026-09-01 10:00:00')",
            ['id' => self::COMPETITION],
        );

        $this->round(self::MORNING, 'Morning', 'solo', self::DAY . ' 09:00:00');
        $this->round(self::EVENING, 'Evening', 'solo', self::DAY . ' 17:00:00');
        $this->round(self::TEAMS_A, 'Teams A', 'team', self::DAY . ' 10:00:00');
        $this->round(self::TEAMS_B, 'Teams B', 'team', self::DAY . ' 14:00:00');

        $participantId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition_participant (id, name, competition_id, player_id) VALUES (:id, 'Owner', :competition, :player)",
            ['id' => $participantId, 'competition' => self::COMPETITION, 'player' => self::OWNER],
        );
        $this->database->executeStatement(
            'INSERT INTO competition_participant_round (id, participant_id, round_id) VALUES (:id, :participant, :round)',
            ['id' => Uuid::uuid7()->toString(), 'participant' => $participantId, 'round' => self::EVENING],
        );
    }

    public function testAddToMyProfilesRoundWinsOverTheEvidence(): void
    {
        // The owner's entry says Evening - the time names Morning (Add to my profile from Morning's official results)
        $timeId = $this->add(roundId: self::MORNING);

        self::assertSame(['round' => self::MORNING, 'chosen' => true], $this->stored($timeId));
    }

    public function testWithoutANamedRoundTheEvidenceDecides(): void
    {
        $timeId = $this->add();

        self::assertSame(['round' => self::EVENING, 'chosen' => false], $this->stored($timeId));
    }

    public function testANamedRoundThatIsNoCandidateIsIgnored(): void
    {
        // A team round for a solo time: the event is still the round's, the rule places the time
        $timeId = $this->add(roundId: self::TEAMS_A);

        self::assertSame(['round' => self::EVENING, 'chosen' => false], $this->stored($timeId));
    }

    public function testAnEditKeepsTheNamedRound(): void
    {
        $timeId = $this->add(roundId: self::MORNING);

        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: self::OWNER_USER,
            puzzleSolvingTimeId: $timeId,
            competitionId: self::COMPETITION,
            time: '00:41:00',
            comment: 'Edited',
            groupPlayers: [],
            finishedAt: new DateTimeImmutable(self::DAY),
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
        ));

        self::assertSame(['round' => self::MORNING, 'chosen' => true], $this->stored($timeId));
    }

    public function testAnUnfinishedResultKeepsItsNamedRound(): void
    {
        $timeId = $this->addUnfinished(roundId: self::MORNING);

        self::assertSame(['round' => self::MORNING, 'chosen' => true], $this->stored($timeId));
    }

    public function testAnUnfinishedResultIsPlacedByItsEvidence(): void
    {
        $timeId = $this->addUnfinished();

        self::assertSame(['round' => self::EVENING, 'chosen' => false], $this->stored($timeId));
    }

    public function testAnUnfinishedResultWhoseRoundCannotBeToldIsRefusedWithItsOwnReason(): void
    {
        $timeId = Uuid::uuid7();

        try {
            // Nobody entered, both rounds possible
            $this->addUnfinished(userId: self::OTHER_USER, timeId: $timeId->toString());
            self::fail('Expected a refusal');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(UnfinishedResultRoundNotIdentified::class, $exception->getPrevious());
            // Every existing catch of "no round with a limit" still catches it
            self::assertInstanceOf(UnfinishedResultNeedsTimeLimit::class, $exception->getPrevious());
        }

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId->toString()]));
    }

    public function testTheFormsHintNamesEverySharingRoundWhenTheyShareTheLimit(): void
    {
        $hint = self::getContainer()->get(UnfinishedResultHint::class);

        // Nothing names a round: the switch is offered for both (same limit), the save then needs evidence or a round
        self::assertSame('Morning / Evening', $hint->forPick(self::COMPETITION, self::P, 1, new DateTimeImmutable(self::DAY))?->name);

        // Add to my profile names the round
        self::assertSame('Evening', $hint->forPick(self::COMPETITION, self::P, 1, new DateTimeImmutable(self::DAY), self::EVENING)?->name);

        // Different limits: nothing to offer without a named round
        $this->database->executeStatement('UPDATE competition_round SET minutes_limit = 90 WHERE id = :id', ['id' => self::MORNING]);
        self::assertNull($hint->forPick(self::COMPETITION, self::P, 1, new DateTimeImmutable(self::DAY)));
        self::assertSame('Morning', $hint->forPick(self::COMPETITION, self::P, 1, new DateTimeImmutable(self::DAY), self::MORNING)?->name);
    }

    public function testTheTrackerChoosesTheRoundOfAnUnlinkedTime(): void
    {
        $timeId = $this->insertTime(owner: self::OTHER, category: 'solo');

        $this->messageBus->dispatch(new ChooseSolvingTimeRound($timeId, self::MORNING, self::OTHER));

        self::assertSame(['round' => self::MORNING, 'chosen' => true], $this->stored($timeId));
    }

    public function testARegisteredTeammateChoosesTheRoundOfTheTeamsTime(): void
    {
        $timeId = $this->insertTime(owner: self::OTHER, category: 'team');

        $this->messageBus->dispatch(new ChooseSolvingTimeRound($timeId, self::TEAMS_B, self::TEAMMATE));

        self::assertSame(['round' => self::TEAMS_B, 'chosen' => true], $this->stored($timeId));
    }

    public function testSomebodyElseCannotChooseTheRound(): void
    {
        $timeId = $this->insertTime(owner: self::OTHER, category: 'solo');

        try {
            $this->messageBus->dispatch(new ChooseSolvingTimeRound($timeId, self::MORNING, self::OWNER));
            self::fail('Expected a refusal');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(CanNotModifyOtherPlayersTime::class, $exception->getPrevious());
        }

        self::assertSame(['round' => null, 'chosen' => false], $this->stored($timeId));
    }

    public function testARoundThatIsNoCandidateIsRefused(): void
    {
        $timeId = $this->insertTime(owner: self::OTHER, category: 'solo');

        try {
            // A team round for a solo time
            $this->messageBus->dispatch(new ChooseSolvingTimeRound($timeId, self::TEAMS_A, self::OTHER));
            self::fail('Expected a refusal');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(RoundIsNoCandidateOfSolvingTime::class, $exception->getPrevious());
        }

        self::assertSame(['round' => null, 'chosen' => false], $this->stored($timeId));
    }

    public function testAnUnfinishedResultMovesOnlyToARoundWithTheSameLimit(): void
    {
        $timeId = $this->addUnfinished(roundId: self::MORNING);
        $this->database->executeStatement('UPDATE competition_round SET minutes_limit = 90 WHERE id = :id', ['id' => self::EVENING]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        try {
            // Its time spent (120 min) would be wrong under Evening's 90 min
            $this->messageBus->dispatch(new ChooseSolvingTimeRound($timeId, self::EVENING, self::OWNER));
            self::fail('Expected a refusal');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(RoundIsNoCandidateOfSolvingTime::class, $exception->getPrevious());
        }

        self::assertSame(['round' => self::MORNING, 'chosen' => true], $this->stored($timeId));

        $this->database->executeStatement('UPDATE competition_round SET minutes_limit = 120 WHERE id = :id', ['id' => self::EVENING]);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->messageBus->dispatch(new ChooseSolvingTimeRound($timeId, self::EVENING, self::OWNER));

        self::assertSame(['round' => self::EVENING, 'chosen' => true], $this->stored($timeId));
    }

    private function add(null|string $roundId = null): string
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: self::OWNER_USER,
            puzzleId: self::P,
            competitionId: self::COMPETITION,
            time: '00:40:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable(self::DAY),
            firstAttempt: false,
            unboxed: false,
            roundId: $roundId,
            createdVia: SolvingTimeSource::Form,
        ));

        return $timeId->toString();
    }

    private function addUnfinished(null|string $roundId = null, string $userId = self::OWNER_USER, null|string $timeId = null): string
    {
        $id = $timeId !== null ? Uuid::fromString($timeId) : Uuid::uuid7();

        $this->messageBus->dispatch(new AddUnfinishedPuzzleSolvingTime(
            timeId: $id,
            userId: $userId,
            puzzleId: self::P,
            competitionId: self::COMPETITION,
            piecesPlaced: 300,
            totalTime: null,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: new DateTimeImmutable(self::DAY),
            firstAttempt: false,
            unboxed: false,
            roundId: $roundId,
            createdVia: SolvingTimeSource::Form,
        ));

        return $id->toString();
    }

    /**
     * A time linked to the event in no round (the rule could not tell) - a team one with TEAMMATE registered on it
     */
    private function insertTime(string $owner, string $category): string
    {
        $id = Uuid::uuid7()->toString();
        $group = $category === 'solo' ? null : json_encode([
            'team_id' => null,
            'puzzlers' => [
                ['player_id' => $owner, 'player_name' => null],
                ['player_id' => self::TEAMMATE, 'player_name' => null],
                ['player_id' => null, 'player_name' => 'Guest Puzzler'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->database->executeStatement(
            "INSERT INTO puzzle_solving_time (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at, competition_id, competition_round_id, puzzling_type, puzzlers_count, created_via)
             VALUES (:id, 2400, :owner, :puzzle, '2026-10-11 10:00:00', true, :team, :finishedAt, :competition, NULL, :category, :count, 'form')",
            [
                'id' => $id,
                'owner' => $owner,
                'puzzle' => self::P,
                'team' => $group,
                'finishedAt' => self::DAY . ' 00:00:00',
                'competition' => self::COMPETITION,
                'category' => $category,
                'count' => $group === null ? 1 : 3,
            ],
        );

        return $id;
    }

    private function round(string $id, string $name, string $category, string $startsAtUtc): void
    {
        $this->database->executeStatement(
            'INSERT INTO competition_round (id, competition_id, name, starts_at, category, minutes_limit) VALUES (:id, :competition, :name, :startsAt, :category, 120)',
            ['id' => $id, 'competition' => self::COMPETITION, 'name' => $name, 'startsAt' => $startsAtUtc, 'category' => $category],
        );
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id) VALUES (:id, :round, :puzzle)',
            ['id' => Uuid::uuid7()->toString(), 'round' => $id, 'puzzle' => self::P],
        );
    }

    /**
     * @return array{round: null|string, chosen: bool}
     */
    private function stored(string $timeId): array
    {
        /** @var array{competition_round_id: null|string, round_chosen: bool} $row */
        $row = $this->database->fetchAssociative(
            'SELECT competition_round_id, round_chosen FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId],
        );

        return ['round' => $row['competition_round_id'], 'chosen' => $row['round_chosen']];
    }
}
