<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleFormatRefused;
use SpeedPuzzling\Web\Message\ArrangeRoundPuzzles;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Query\RoundPuzzlesVisibleNow;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RoundPuzzleSlot;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Value\OnlineTimingAvailability;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundPuzzleLabel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The round format model (competition toolkit A2 - R-review-and-plan.md §1.1, A-round-formats.md §2.1, §2.5): `total`
 * is every round's default, `pick` needs 1 ≤ K ≤ N, removing a puzzle never leaves fewer than K, a round with online
 * timing stays `total`; positions and labels are computed on read for rounds never arranged (add order, letters).
 */
final class RoundPuzzleFormatTest extends KernelTestCase
{
    use RoundPuzzleFormatSetup;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private string $fixtureRow;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->fixtureRow = self::addRoundPuzzles($this->database);
    }

    public function testEveryRoundIsOneResultForTheRoundUntilTheOrganiserSaysOtherwise(): void
    {
        self::assertSame(['total', null], $this->format());
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM competition_round WHERE puzzle_format <> 'total' OR puzzles_to_solve IS NOT NULL"));
    }

    public function testRoundsNeverArrangedGetPlacesInAddOrderAndLetters(): void
    {
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM competition_round_puzzle WHERE position IS NOT NULL OR label IS NOT NULL'));

        $slots = $this->slots();

        self::assertSame([self::SECRET, self::PUBLIC, $this->fixtureRow], array_map(static fn (RoundPuzzleSlot $slot): string => $slot->roundPuzzleId, $slots));
        self::assertSame([1, 2, 3], array_map(static fn (RoundPuzzleSlot $slot): int => $slot->position, $slots));
        self::assertSame(['A', 'B', 'C'], array_map(static fn (RoundPuzzleSlot $slot): string => $slot->label, $slots));

        // The secret puzzle is a place with its label only - no puzzle, no moment (a manual reveal)
        self::assertTrue($slots[0]->isHidden());
        self::assertNull($slots[0]->revealsAt);
        self::assertStringNotContainsString(self::SECRET_NAME, serialize($slots));
        self::assertStringNotContainsString(PuzzleFixture::PUZZLE_1500_01, serialize($slots));
        self::assertSame(self::PUBLIC_NAME, $slots[1]->puzzle?->puzzleName);
    }

    public function testPickKOfTheRoundsPuzzles(): void
    {
        $this->editFormat(RoundPuzzleFormat::Pick, 2);
        self::assertSame(['pick', 2], $this->format());

        // K = N: "Solve all 3"
        $this->editFormat(RoundPuzzleFormat::Pick, 3);
        self::assertSame(['pick', 3], $this->format());

        // Back to one result for the round: K is forgotten
        $this->editFormat(RoundPuzzleFormat::Total, 3);
        self::assertSame(['total', null], $this->format());
    }

    /**
     * @return iterable<string, array{null|int}>
     */
    public static function invalidK(): iterable
    {
        yield 'more than the round has' => [4];
        yield 'zero' => [0];
        yield 'missing' => [null];
    }

    #[DataProvider('invalidK')]
    public function testKOutsideTheRoundsPuzzlesIsRefusedAndNothingChanges(null|int $toSolve): void
    {
        $this->editFormat(RoundPuzzleFormat::Pick, 2);

        try {
            $this->editFormat(RoundPuzzleFormat::Pick, $toSolve, name: 'Renamed meanwhile');
            self::fail('Refused expected');
        } catch (RoundPuzzleFormatRefused $refused) {
            self::assertSame(RoundPuzzleFormatRefused::TO_SOLVE_RANGE, $refused->reason);
            self::assertSame(3, $refused->puzzlesCount);
        }

        self::assertSame(['pick', 2], $this->format());
        self::assertSame('Group B', $this->database->fetchOne('SELECT name FROM competition_round WHERE id = :id', ['id' => self::ROUND]));
    }

    public function testAnEditWithoutTheFormatKeepsIt(): void
    {
        self::makePick($this->database, 2);

        $this->editFormat(null, null, name: 'Group B renamed');

        self::assertSame(['pick', 2], $this->format());
        self::assertSame('Group B renamed', $this->database->fetchOne('SELECT name FROM competition_round WHERE id = :id', ['id' => self::ROUND]));
    }

    public function testRemovingAPuzzleNeverLeavesFewerThanK(): void
    {
        self::makePick($this->database, 3);

        try {
            $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound(self::PUBLIC));
            self::fail('Refused expected');
        } catch (RoundPuzzleFormatRefused $refused) {
            self::assertSame(RoundPuzzleFormatRefused::TOO_FEW_PUZZLES, $refused->reason);
            self::assertSame(3, $refused->puzzlesToSolve);
            self::assertSame(2, $refused->puzzlesCount);
        }

        self::assertSame(3, $this->puzzlesCount());

        // Lowered to 2: the third puzzle may go
        self::makePick($this->database, 2);
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound(self::PUBLIC));

        self::assertSame(2, $this->puzzlesCount());
    }

    public function testSettingTheRoundsPuzzlesNeverLeavesFewerThanK(): void
    {
        self::makePick($this->database, 2);

        try {
            $this->messageBus->dispatch(new SetCompetitionRoundPuzzles(self::ROUND, [PuzzleFixture::PUZZLE_5000]));
            self::fail('Refused expected');
        } catch (RoundPuzzleFormatRefused $refused) {
            self::assertSame(RoundPuzzleFormatRefused::TOO_FEW_PUZZLES, $refused->reason);
        }

        self::assertSame(3, $this->puzzlesCount());
    }

    public function testATotalRoundLosesPuzzlesAsBefore(): void
    {
        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound(self::PUBLIC));

        self::assertSame(2, $this->puzzlesCount());
    }

    public function testARoundWithOnlineTimingStaysOneResultForTheRound(): void
    {
        try {
            $this->editFormat(RoundPuzzleFormat::Pick, 1, roundId: OnlineRoundsFixture::ROUND_ONLINE_SOLO);
            self::fail('Refused expected');
        } catch (RoundPuzzleFormatRefused $refused) {
            self::assertSame(RoundPuzzleFormatRefused::ONLINE_TIMING, $refused->reason);
        }

        self::assertSame('total', $this->database->fetchOne('SELECT puzzle_format FROM competition_round WHERE id = :id', ['id' => OnlineRoundsFixture::ROUND_ONLINE_SOLO]));
    }

    public function testOnlineTimingIsNotOfferedForAPickRound(): void
    {
        self::assertSame(OnlineTimingAvailability::PickFormat, OnlineTimingAvailability::of(true, RoundCategory::Solo, RoundPuzzleFormat::Pick));
        self::assertSame(OnlineTimingAvailability::Available, OnlineTimingAvailability::of(true, RoundCategory::Solo));
        self::assertSame(OnlineTimingAvailability::InPersonEvent, OnlineTimingAvailability::of(false, RoundCategory::Solo, RoundPuzzleFormat::Pick));
    }

    public function testTheOrganiserArrangesThePuzzlesAndLabelsThem(): void
    {
        $this->messageBus->dispatch(new ArrangeRoundPuzzles(
            roundId: self::ROUND,
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundPuzzleIds: [$this->fixtureRow, self::SECRET],
            labels: [$this->fixtureRow => '  Blue   box ', self::SECRET => ''],
        ));

        $rows = $this->database->fetchAllKeyValue('SELECT id, position FROM competition_round_puzzle WHERE round_id = :id', ['id' => self::ROUND]);
        // Listed first in the listed order, the one left out after them
        self::assertEquals([$this->fixtureRow => 1, self::SECRET => 2, self::PUBLIC => 3], $rows);

        $slots = $this->slots();
        self::assertSame(['Blue box', 'B', 'C'], array_map(static fn (RoundPuzzleSlot $slot): string => $slot->label, $slots));
        self::assertSame([$this->fixtureRow, self::SECRET, self::PUBLIC], array_map(static fn (RoundPuzzleSlot $slot): string => $slot->roundPuzzleId, $slots));
    }

    public function testATooLongLabelChangesNothing(): void
    {
        try {
            $this->messageBus->dispatch(new ArrangeRoundPuzzles(
                roundId: self::ROUND,
                competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
                roundPuzzleIds: [self::PUBLIC],
                labels: [self::PUBLIC => 'Fine', self::SECRET => str_repeat('x', RoundPuzzleLabel::MAX_LENGTH + 1)],
            ));
            self::fail('Refused expected');
        } catch (HandlerFailedException $failed) {
            self::assertInstanceOf(\InvalidArgumentException::class, $failed->getPrevious());
        }

        self::assertSame(0, $this->number('SELECT COUNT(*) FROM competition_round_puzzle WHERE position IS NOT NULL OR label IS NOT NULL'));
    }

    public function testAPuzzleAddedAfterAnArrangementComesLast(): void
    {
        $this->messageBus->dispatch(new ArrangeRoundPuzzles(self::ROUND, OfficialResultsFixture::COMPETITION_RESULTS_CUP, [self::PUBLIC, self::SECRET, $this->fixtureRow], []));

        $this->database->insert('competition_round_puzzle', [
            'id' => '018d0020-0000-0000-0000-00000000a200',
            'round_id' => self::ROUND,
            'puzzle_id' => PuzzleFixture::PUZZLE_3000,
            'hide_until_round_starts' => false,
            'reveal_mode' => 'automatic',
            'hides_everywhere' => false,
        ], ['hide_until_round_starts' => 'boolean', 'hides_everywhere' => 'boolean']);

        self::assertSame(
            [self::PUBLIC, self::SECRET, $this->fixtureRow, '018d0020-0000-0000-0000-00000000a200'],
            array_map(static fn (RoundPuzzleSlot $slot): string => $slot->roundPuzzleId, $this->slots()),
        );
    }

    private function editFormat(null|RoundPuzzleFormat $format, null|int $toSolve, string $roundId = self::ROUND, null|string $name = null): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->clear();
        $round = self::getContainer()->get(CompetitionRoundRepository::class)->get($roundId);

        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: $roundId,
            competitionId: $round->competition->id->toString(),
            name: $name ?? $round->name,
            minutesLimit: $round->minutesLimit ?? 90,
            startsAt: $round->startsAt,
            timezone: $round->displayTimezone(),
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $round->category,
            keepFields: $name === null ? EditCompetitionRound::FIELDS : [],
            puzzleFormat: $format,
            puzzlesToSolve: $toSolve,
        ));
        $entityManager->clear();
    }

    /**
     * @return array{string, null|int}
     */
    private function format(): array
    {
        /** @var array{puzzle_format: string, puzzles_to_solve: null|int} $row */
        $row = $this->database->fetchAssociative('SELECT puzzle_format, puzzles_to_solve FROM competition_round WHERE id = :id', ['id' => self::ROUND]);

        return [$row['puzzle_format'], $row['puzzles_to_solve']];
    }

    private function puzzlesCount(): int
    {
        return $this->number('SELECT COUNT(*) FROM competition_round_puzzle WHERE round_id = :id', ['id' => self::ROUND]);
    }

    /**
     * @return list<RoundPuzzleSlot>
     */
    private function slots(): array
    {
        return self::getContainer()->get(RoundPuzzlesVisibleNow::class)->forRound(self::ROUND, TestClock::now())->slots;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function number(string $sql, array $parameters = []): int
    {
        $value = $this->database->fetchOne($sql, $parameters);
        assert(is_int($value) || is_string($value));

        return (int) $value;
    }
}
