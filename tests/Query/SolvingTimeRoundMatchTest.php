<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\PuzzlingType;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The round rule (SolvingTimeRoundMatch, docs/features/competitions-management/shared-puzzles-and-divisions.md §2.5
 * adjusted to the Review amendments A1-A4) - every row answered by the resolver (the entity, before flush) and by the
 * reconciler (the stored row) alike.
 *
 * A made-up St. Paul-like event in New York's zone (the event's country - the rounds keep no zone of their own, so the
 * rule must read them the way RoundTimezone::resolve() does): Challenge (teams, Sat 09:00), Open #1 (teams, Sat 19:00 -
 * already Sunday in UTC and in Prague), Open #2 (teams, Sun 10:00) share puzzle P; Family (teams, Sun 10:00) has Q; a
 * solo round (Sat 08:00) has P too. Puzzles shared by rounds of one category are inserted directly - the handlers still
 * refuse them until package S2.
 */
final class SolvingTimeRoundMatchTest extends KernelTestCase
{
    private const string COMPETITION = '019a8000-0000-7000-8000-000000000001';
    private const string CHALLENGE = '019a8000-0000-7000-8000-000000000011';
    private const string OPEN_1 = '019a8000-0000-7000-8000-000000000012';
    private const string OPEN_2 = '019a8000-0000-7000-8000-000000000013';
    private const string FAMILY = '019a8000-0000-7000-8000-000000000014';
    private const string SOLO = '019a8000-0000-7000-8000-000000000015';

    private const string P = PuzzleFixture::PUZZLE_500_04;
    private const string Q = PuzzleFixture::PUZZLE_500_05;
    private const string P_MERGED = PuzzleFixture::PUZZLE_500_03;

    // The owner of every time, a teammate on its group, somebody else
    private const string OWNER = PlayerFixture::PLAYER_REGULAR;
    private const string TEAMMATE = PlayerFixture::PLAYER_WITH_FAVORITES;
    private const string OTHER = PlayerFixture::PLAYER_ADMIN;

    // Saved on Monday, after every round - only the rows about tracked_at save earlier
    private const string MONDAY = '2027-02-08 15:00:00';
    private const string SATURDAY = '2027-02-06';
    private const string SUNDAY = '2027-02-07';

    private Connection $database;

    /** @var array<string, string> person => participant id */
    private array $participants = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);

        $this->database->executeStatement(
            "INSERT INTO competition (id, name, location_country_code, is_online) VALUES (:id, 'Lantern Winter Carnival', 'us', false)",
            ['id' => self::COMPETITION],
        );

        // UTC instants of the New York local starts (EST = UTC-5)
        $this->round(self::CHALLENGE, 'Challenge', 'team', '2027-02-06 14:00:00', [self::P]);
        $this->round(self::OPEN_1, 'Open #1', 'team', '2027-02-07 00:00:00', [self::P]);
        $this->round(self::OPEN_2, 'Open #2', 'team', '2027-02-07 15:00:00', [self::P]);
        $this->round(self::FAMILY, 'Family', 'team', '2027-02-07 15:00:00', [self::Q]);
        $this->round(self::SOLO, 'Solo', 'solo', '2027-02-06 13:00:00', [self::P]);

        foreach ([self::OWNER, self::TEAMMATE, self::OTHER] as $player) {
            $this->participants[$player] = Uuid::uuid7()->toString();
            $this->database->executeStatement(
                "INSERT INTO competition_participant (id, name, competition_id, player_id) VALUES (:id, 'Puzzler', :competition, :player)",
                ['id' => $this->participants[$player], 'competition' => self::COMPETITION, 'player' => $player],
            );
        }
    }

    /**
     * @return iterable<string, array{0: callable(self): array{0: string, 1: null|string}, 1: null|string, 2: bool}>
     *     a setup returning [time id, its stored round before the rule runs], the expected round, the expected chosen flag
     */
    public static function provideMatrix(): iterable
    {
        yield 'solo time on P - the only solo candidate' => [
            static fn (self $test): array => [$test->time(category: 'solo'), null],
            self::SOLO,
            false,
        ];

        yield 'owner in Open #1\'s team only' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(), null];
            },
            self::OPEN_1,
            false,
        ];

        yield 'owner in Challenge and Open #1, the time is Open #1\'s recorded result' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::OWNER], seconds: 4325);
                $test->team(self::OPEN_1, [self::OWNER], seconds: 5500);

                return [$test->time(seconds: 5500), null];
            },
            self::OPEN_1,
            false,
        ];

        yield 'owner in Challenge and Open #2, no results, finished Sun - equal dates never choose' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::OWNER]);
                $test->team(self::OPEN_2, [self::OWNER]);

                return [$test->time(finishedAt: self::SUNDAY), null];
            },
            null,
            false,
        ];

        yield 'owner in Challenge and Open #2, no results, finished Sat - Sunday\'s round excluded' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::OWNER]);
                $test->team(self::OPEN_2, [self::OWNER]);

                return [$test->time(finishedAt: self::SATURDAY), null];
            },
            self::CHALLENGE,
            false,
        ];

        yield 'owner in Challenge and Open #1, no results, finished Sat, form save' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::OWNER]);
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(finishedAt: self::SATURDAY), null];
            },
            null,
            false,
        ];

        yield 'same, saved from the stopwatch right after Open #1 - no stage L' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::OWNER]);
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(finishedAt: self::SATURDAY, trackedAt: '2027-02-07 01:35:00', createdVia: 'stopwatch'), null];
            },
            null,
            false,
        ];

        yield 'no entries, finished Sun' => [
            static fn (self $test): array => [$test->time(finishedAt: self::SUNDAY), null],
            null,
            false,
        ];

        yield 'no entries, finished Sat - Open #1 is a Saturday round in its own zone' => [
            static fn (self $test): array => [$test->time(finishedAt: self::SATURDAY), null],
            null,
            false,
        ];

        yield 'no entries, day known to the month only' => [
            static fn (self $test): array => [$test->time(finishedAt: '2027-02-01', precision: 'month'), null],
            null,
            false,
        ];

        yield 'saved before Open #1 began - only Challenge had started' => [
            static fn (self $test): array => [$test->time(finishedAt: self::SATURDAY, trackedAt: '2027-02-06 18:00:00'), null],
            self::CHALLENGE,
            false,
        ];

        yield 'a spreadsheet import\'s made-up tracked_at (noon of the solve day) excludes nothing' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(finishedAt: self::SATURDAY, trackedAt: '2027-02-06 17:00:00', createdVia: 'spreadsheet_import'), null];
            },
            self::OPEN_1,
            false,
        ];

        yield 'a row older than created_via: its tracked_at excludes nothing' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(finishedAt: self::SATURDAY, trackedAt: '2027-02-06 17:00:00', createdVia: null), null];
            },
            self::OPEN_1,
            false,
        ];

        yield 'saved before Open #1 began, the owner entered only in Open #1 - entries of excluded rounds count too' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(finishedAt: self::SATURDAY, trackedAt: '2027-02-06 18:00:00'), null];
            },
            null,
            false,
        ];

        yield 'chosen round of another event (the time moved): no longer the player\'s placing - the rule decides' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(round: $test->roundOfAnotherEvent(), chosen: true), 'another'];
            },
            self::OPEN_1,
            false,
        ];

        yield 'owner without an entry, a linked teammate in Challenge only' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::TEAMMATE]);

                return [$test->time(), null];
            },
            self::CHALLENGE,
            false,
        ];

        yield 'owner did not start in Challenge, entered in Open #1' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::OWNER], didNotStart: true);
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(), null];
            },
            self::OPEN_1,
            false,
        ];

        yield 'owner in Challenge\'s team, teammate in Open #1\'s team - inconsistent' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::OWNER]);
                $test->team(self::OPEN_1, [self::TEAMMATE]);

                return [$test->time(), null];
            },
            null,
            false,
        ];

        yield 'entry of a deleted participant is ignored' => [
            static function (self $test): array {
                $test->team(self::CHALLENGE, [self::OWNER]);
                $test->team(self::OPEN_1, [self::OWNER], deletedParticipant: true);

                return [$test->time(), null];
            },
            self::CHALLENGE,
            false,
        ];

        yield 'somebody else\'s entry is no evidence' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OTHER]);

                return [$test->time(), null];
            },
            null,
            false,
        ];

        yield 'chosen Challenge wins over the owner\'s entry in Open #1' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(round: self::CHALLENGE, chosen: true), self::CHALLENGE];
            },
            self::CHALLENGE,
            true,
        ];

        yield 'chosen Challenge, P removed from Challenge, two candidates left - unlinked, flag cleared' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OWNER]);
                $time = $test->time(round: self::CHALLENGE, chosen: true);
                $test->removePuzzle(self::CHALLENGE, self::P);

                return [$time, self::CHALLENGE];
            },
            null,
            false,
        ];

        yield 'chosen Challenge, P removed from Challenge and Open #2 - the single candidate left' => [
            static function (self $test): array {
                $time = $test->time(round: self::CHALLENGE, chosen: true);
                $test->removePuzzle(self::CHALLENGE, self::P);
                $test->removePuzzle(self::OPEN_2, self::P);

                return [$time, self::CHALLENGE];
            },
            self::OPEN_1,
            false,
        ];

        yield 'imported event result in Challenge keeps it' => [
            static fn (self $test): array => [$test->time(round: self::CHALLENGE, createdVia: 'event_import', owner: null), self::CHALLENGE],
            self::CHALLENGE,
            false,
        ];

        yield 'ambiguous, the round the time holds is a remaining candidate - kept (A3b, sticky)' => [
            static fn (self $test): array => [$test->time(round: self::CHALLENGE), self::CHALLENGE],
            self::CHALLENGE,
            false,
        ];

        yield 'ambiguous, the round the time holds is excluded (Sunday\'s round, finished Sat) - unlinked' => [
            static fn (self $test): array => [$test->time(round: self::OPEN_2, finishedAt: self::SATURDAY), self::OPEN_2],
            null,
            false,
        ];

        yield 'the evidence names another round than the one the time holds - moved' => [
            static function (self $test): array {
                $test->team(self::OPEN_1, [self::OWNER]);

                return [$test->time(round: self::CHALLENGE), self::CHALLENGE];
            },
            self::OPEN_1,
            false,
        ];
    }

    /**
     * @param callable(self): array{0: string, 1: null|string} $setUp
     */
    #[DataProvider('provideMatrix')]
    public function testResolverAndReconcilerGiveTheSameAnswer(callable $setUp, null|string $expectedRound, bool $expectedChosen): void
    {
        [$timeId] = $setUp($this);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $time = $entityManager->find(PuzzleSolvingTime::class, $timeId);
        self::assertInstanceOf(PuzzleSolvingTime::class, $time);

        $resolution = self::getContainer()->get(SolvingTimeRoundResolver::class)->resolve($time);
        self::assertSame($expectedRound, $resolution->round?->id->toString(), 'resolver');
        self::assertSame($expectedChosen, $resolution->chosen, 'resolver chosen');

        $entityManager->clear();
        self::getContainer()->get(RoundResultsReconciler::class)->reconcile(Uuid::fromString(self::COMPETITION));

        self::assertSame(['round' => $expectedRound, 'chosen' => $expectedChosen], $this->stored($timeId), 'reconciler');

        // Idempotent for the reconciler's own answer: a second run changes nothing it just wrote - except the A4
        // transition, after which the time is an ordinary derived one
        if ($expectedChosen || $expectedRound !== null) {
            self::assertSame(['linked' => 0, 'unlinked' => 0], self::getContainer()->get(RoundResultsReconciler::class)->reconcile(Uuid::fromString(self::COMPETITION)));
        }
    }

    public function testSingleCandidateCostsOneStatementAndTheRoundsGet(): void
    {
        $timeId = $this->time(category: 'solo');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $time = $entityManager->find(PuzzleSolvingTime::class, $timeId);
        self::assertInstanceOf(PuzzleSolvingTime::class, $time);

        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $resolution = self::getContainer()->get(SolvingTimeRoundResolver::class)->resolve($time);

        self::assertSame(self::SOLO, $resolution->round?->id->toString());
        self::assertSame(1, $resolution->candidates);
        // The rule's one statement + the round's get()
        $statements = $debugDataHolder->getData()['default'] ?? [];
        self::assertIsArray($statements);
        self::assertCount(2, $statements);
    }

    public function testNamedRoundWinsWhileItIsACandidate(): void
    {
        $this->team(self::OPEN_1, [self::OWNER]);
        $timeId = $this->time();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $time = $entityManager->find(PuzzleSolvingTime::class, $timeId);
        self::assertInstanceOf(PuzzleSolvingTime::class, $time);
        $resolver = self::getContainer()->get(SolvingTimeRoundResolver::class);

        $named = $resolver->resolve($time, $this->roundEntity(self::CHALLENGE));
        self::assertSame(self::CHALLENGE, $named->round?->id->toString());
        self::assertTrue($named->chosen);

        // A named round that is not a candidate (another category) is ignored - the rule decides
        $notACandidate = $resolver->resolve($time, $this->roundEntity(self::SOLO));
        self::assertSame(self::OPEN_1, $notACandidate->round?->id->toString());
        self::assertFalse($notACandidate->chosen);
    }

    public function testUnfinishedPathNamesNoRoundWhenThePuzzleIsShared(): void
    {
        $resolver = self::getContainer()->get(SolvingTimeRoundResolver::class);

        self::assertNull($resolver->resolveFor(self::COMPETITION, self::P, PuzzlingType::Team));
        self::assertSame(self::FAMILY, $resolver->resolveFor(self::COMPETITION, self::Q, PuzzlingType::Team)?->id->toString());
        self::assertSame(self::SOLO, $resolver->resolveFor(self::COMPETITION, self::P, PuzzlingType::Solo)?->id->toString());
    }

    /**
     * A puzzle merge moves the merged puzzle's round puzzle rows onto the survivor without any check
     * (ApprovePuzzleMergeRequestHandler) - two rounds of one category may then hold one puzzle. The reconcile no longer
     * files every time in the earliest of them: evidence moves a time, a time without evidence keeps its round (A3b).
     */
    public function testAfterAPuzzleMergeEvidenceMovesATimeAndTheRestKeepTheirRound(): void
    {
        $this->removePuzzle(self::OPEN_1, self::P);
        $this->removePuzzle(self::OPEN_2, self::P);
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id) VALUES (:id, :round, :puzzle)',
            ['id' => Uuid::uuid7()->toString(), 'round' => self::OPEN_1, 'puzzle' => self::P_MERGED],
        );
        $this->team(self::OPEN_1, [self::OWNER]);

        $onSurvivor = $this->time(round: self::CHALLENGE, finishedAt: self::SATURDAY);
        $onMergedWithEntry = $this->time(puzzle: self::P_MERGED, round: self::OPEN_1, finishedAt: self::SATURDAY);
        $onMergedWithoutEntry = $this->time(puzzle: self::P_MERGED, round: self::OPEN_1, finishedAt: self::SATURDAY, owner: self::OTHER, team: false);

        $reconciler = self::getContainer()->get(RoundResultsReconciler::class);
        self::assertSame(['linked' => 0, 'unlinked' => 0], $reconciler->reconcile(Uuid::fromString(self::COMPETITION)));

        // The merge, as ApprovePuzzleMergeRequestHandler writes it
        $this->database->executeStatement(
            'UPDATE competition_round_puzzle SET puzzle_id = :survivor WHERE puzzle_id = :merged AND round_id = :round',
            ['survivor' => self::P, 'merged' => self::P_MERGED, 'round' => self::OPEN_1],
        );
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET puzzle_id = :survivor WHERE puzzle_id = :merged AND competition_id = :competition',
            ['survivor' => self::P, 'merged' => self::P_MERGED, 'competition' => self::COMPETITION],
        );

        $reconciler->reconcile(Uuid::fromString(self::COMPETITION));

        // The owner's entry in Open #1 places both of their times there (the one stored in Challenge moves); the other
        // player's time has no evidence and keeps the round it holds (A3b - before, every time would have been filed in
        // Challenge, the earliest round)
        self::assertSame(self::OPEN_1, $this->stored($onSurvivor)['round']);
        self::assertSame(self::OPEN_1, $this->stored($onMergedWithEntry)['round']);
        self::assertSame(self::OPEN_1, $this->stored($onMergedWithoutEntry)['round']);
    }

    /**
     * @param list<string> $persons
     */
    public function team(string $roundId, array $persons, null|int $seconds = null, bool $didNotStart = false, bool $deletedParticipant = false): void
    {
        $teamId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            'INSERT INTO competition_team (id, round_id, result_seconds, result_did_not_start) VALUES (:id, :round, :seconds, :dns)',
            ['id' => $teamId, 'round' => $roundId, 'seconds' => $seconds, 'dns' => $didNotStart],
            ['dns' => 'boolean'],
        );

        foreach ($persons as $person) {
            $participantId = $this->participants[$person];

            if ($deletedParticipant) {
                $participantId = Uuid::uuid7()->toString();
                $this->database->executeStatement(
                    "INSERT INTO competition_participant (id, name, competition_id, player_id, deleted_at) VALUES (:id, 'Puzzler', :competition, :player, '2027-01-01 10:00:00')",
                    ['id' => $participantId, 'competition' => self::COMPETITION, 'player' => $person],
                );
            }

            $this->database->executeStatement(
                'INSERT INTO competition_participant_round (id, participant_id, round_id, team_id) VALUES (:id, :participant, :round, :team)',
                ['id' => Uuid::uuid7()->toString(), 'participant' => $participantId, 'round' => $roundId, 'team' => $teamId],
            );
        }
    }

    /**
     * A team time of OWNER with TEAMMATE and a guest (or a solo one) on P, linked to the competition
     */
    public function time(
        string $category = 'team',
        string $puzzle = self::P,
        null|string $round = null,
        bool $chosen = false,
        null|int $seconds = 4800,
        string $finishedAt = self::SATURDAY,
        null|string $precision = null,
        string $trackedAt = self::MONDAY,
        null|string $createdVia = 'form',
        null|string $owner = self::OWNER,
        bool $team = true,
    ): string {
        $id = Uuid::uuid7()->toString();
        $group = null;

        if ($category !== 'solo' && $team) {
            $group = json_encode([
                'team_id' => null,
                'puzzlers' => [
                    ['player_id' => $owner, 'player_name' => null],
                    ['player_id' => self::TEAMMATE, 'player_name' => null],
                    ['player_id' => null, 'player_name' => 'Guest Puzzler'],
                ],
            ], JSON_THROW_ON_ERROR);
        }

        $this->database->executeStatement(
            'INSERT INTO puzzle_solving_time (id, seconds_to_solve, player_id, puzzle_id, tracked_at, verified, team, finished_at, competition_id, competition_round_id, round_chosen, puzzling_type, puzzlers_count, created_via, finished_at_precision)
             VALUES (:id, :seconds, :owner, :puzzle, :trackedAt, true, :team, :finishedAt, :competition, :round, :chosen, :category, :count, :createdVia, :precision)',
            [
                'id' => $id,
                'seconds' => $seconds,
                'owner' => $owner,
                'puzzle' => $puzzle,
                'trackedAt' => $trackedAt,
                'team' => $group,
                'finishedAt' => $finishedAt . ' 00:00:00',
                'competition' => self::COMPETITION,
                'round' => $round,
                'chosen' => $chosen,
                'category' => $category,
                'count' => $group === null ? 1 : 3,
                'createdVia' => $createdVia,
                'precision' => $precision,
            ],
            ['chosen' => 'boolean'],
        );

        return $id;
    }

    public function roundOfAnotherEvent(): string
    {
        $competitionId = Uuid::uuid7()->toString();
        $roundId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO competition (id, name, is_online) VALUES (:id, 'Lantern Spring Jam', false)",
            ['id' => $competitionId],
        );
        $this->database->executeStatement(
            "INSERT INTO competition_round (id, competition_id, name, starts_at, category) VALUES (:id, :competition, 'Teams', '2027-01-10 15:00:00', 'team')",
            ['id' => $roundId, 'competition' => $competitionId],
        );
        $this->database->executeStatement(
            'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id) VALUES (:id, :round, :puzzle)',
            ['id' => Uuid::uuid7()->toString(), 'round' => $roundId, 'puzzle' => self::P],
        );

        return $roundId;
    }

    public function removePuzzle(string $roundId, string $puzzleId): void
    {
        $this->database->executeStatement(
            'DELETE FROM competition_round_puzzle WHERE round_id = :round AND puzzle_id = :puzzle',
            ['round' => $roundId, 'puzzle' => $puzzleId],
        );
    }

    /**
     * @param list<string> $puzzles
     */
    private function round(string $id, string $name, string $category, string $startsAtUtc, array $puzzles): void
    {
        $this->database->executeStatement(
            'INSERT INTO competition_round (id, competition_id, name, starts_at, category, minutes_limit) VALUES (:id, :competition, :name, :startsAt, :category, 120)',
            ['id' => $id, 'competition' => self::COMPETITION, 'name' => $name, 'startsAt' => $startsAtUtc, 'category' => $category],
        );

        foreach ($puzzles as $puzzle) {
            $this->database->executeStatement(
                'INSERT INTO competition_round_puzzle (id, round_id, puzzle_id) VALUES (:id, :round, :puzzle)',
                ['id' => Uuid::uuid7()->toString(), 'round' => $id, 'puzzle' => $puzzle],
            );
        }
    }

    private function roundEntity(string $roundId): CompetitionRound
    {
        $round = self::getContainer()->get(EntityManagerInterface::class)->find(CompetitionRound::class, $roundId);
        self::assertNotNull($round);

        return $round;
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
