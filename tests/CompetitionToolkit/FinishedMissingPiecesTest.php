<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Finished with pieces missing (competition toolkit T6, official-results.md "Finished with pieces missing"): the
 * `missing_pieces` field of RecordRoundResults - its own field next to the result, so a device that does not know it
 * never wipes it. Group A's puzzle has 1000 pieces; Anna finished in 3600 s, Filip has no result.
 */
final class FinishedMissingPiecesTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAFinishedTimeWithPiecesMissingIsRecordedInOneSet(): void
    {
        $recorded = $this->record([
            self::change(self::filip(), 'result', null, ['seconds' => 5025]),
            self::change(self::filip(), 'missing_pieces', null, 1),
        ]);

        self::assertSame(['applied', 'applied'], self::statuses($recorded));
        self::assertSame(1, $this->column(OfficialResultsFixture::ENTRY_A_FILIP));
        self::assertSame(1, $recorded->outcomes[1]->jsonSerialize()['current']);

        // The entry JSON carries the count; an entry without one keeps its shape
        $entries = $this->entries();
        self::assertSame(1, $entries[self::filip()]['missingPieces']);
        self::assertSame(['seconds' => 5025], $entries[self::filip()]['result']);
        self::assertArrayNotHasKey('missingPieces', $entries[self::anna()]);
    }

    public function testATimeCorrectedByADeviceThatDoesNotKnowTheFieldKeepsTheCount(): void
    {
        $this->record([self::change(self::anna(), 'missing_pieces', null, 2)]);

        // An old page: it compares results by their seconds and sends the result it got back as `from`
        $recorded = $this->record([self::change(self::anna(), 'result', ['seconds' => 3600], ['seconds' => 3500])]);

        self::assertSame(['applied'], self::statuses($recorded));
        self::assertSame(2, $this->column(OfficialResultsFixture::ENTRY_A_ANNA));
        self::assertSame(2, $this->entries()[self::anna()]['missingPieces']);
    }

    public function testAnyOtherResultDropsTheCount(): void
    {
        $this->record([self::change(self::anna(), 'missing_pieces', null, 2)]);

        $recorded = $this->record([self::change(self::anna(), 'result', ['seconds' => 3600], ['piecesPlaced' => 900])]);

        self::assertSame(['applied'], self::statuses($recorded));
        self::assertNull($this->column(OfficialResultsFixture::ENTRY_A_ANNA));

        // Finished again: complete until somebody records a count
        $this->record([self::change(self::anna(), 'result', ['piecesPlaced' => 900], ['seconds' => 3700])]);
        self::assertNull($this->column(OfficialResultsFixture::ENTRY_A_ANNA));
        self::assertArrayNotHasKey('missingPieces', $this->entries()[self::anna()]);
    }

    public function testACountIsRefusedWithoutAFinishedTimeAndOutOfRange(): void
    {
        $recorded = $this->record([
            // Filip has no result
            self::change(self::filip(), 'missing_pieces', null, 1),
            self::change(self::anna(), 'missing_pieces', null, 0),
            // The puzzle has 1000 pieces
            self::change(self::anna(), 'missing_pieces', null, 1000),
            self::change(self::anna(), 'missing_pieces', null, 'one'),
            self::change(self::anna(), 'missing_pieces', null, 999),
        ]);

        self::assertSame(['rejected', 'rejected', 'rejected', 'rejected', 'applied'], self::statuses($recorded));
        self::assertSame(
            ['missing_pieces_not_finished', 'invalid_missing_pieces', 'invalid_missing_pieces', 'invalid_change', null],
            array_map(static fn (RoundResultChangeOutcome $outcome): null|string => $outcome->reason, $recorded->outcomes),
        );
        self::assertNull($this->column(OfficialResultsFixture::ENTRY_A_FILIP));
        self::assertSame(999, $this->column(OfficialResultsFixture::ENTRY_A_ANNA));
    }

    public function testTheCountIsCheckedThreeWayAndClearedWithNull(): void
    {
        $this->record([self::change(self::anna(), 'missing_pieces', null, 2)]);

        $stale = $this->record([self::change(self::anna(), 'missing_pieces', 1, 3)]);
        self::assertSame(['conflict'], self::statuses($stale));
        self::assertSame(2, $stale->outcomes[0]->jsonSerialize()['current']);

        $cleared = $this->record([self::change(self::anna(), 'missing_pieces', 2, null)]);
        self::assertSame(['applied'], self::statuses($cleared));
        self::assertNull($this->column(OfficialResultsFixture::ENTRY_A_ANNA));
    }

    public function testAResultAndItsCountApplyTogetherOrNotAtAll(): void
    {
        $recorded = $this->record([
            // Anna's result is 3600, not none - a conflict
            self::change(self::anna(), 'result', null, ['seconds' => 3500]),
            self::change(self::anna(), 'missing_pieces', null, 1),
        ]);

        self::assertSame(['conflict', 'rejected'], self::statuses($recorded));
        self::assertSame('group_refused', $recorded->outcomes[1]->reason);
        self::assertNull($this->column(OfficialResultsFixture::ENTRY_A_ANNA));
    }

    public function testARefereeRecordsTheCount(): void
    {
        $recorded = $this->record([self::change(self::anna(), 'missing_pieces', null, 1)], resultsOnly: true);

        self::assertSame(['applied'], self::statuses($recorded));
        self::assertSame(1, $this->column(OfficialResultsFixture::ENTRY_A_ANNA));
    }

    /**
     * @param list<array<string, mixed>> $changes wire format
     */
    private function record(array $changes, bool $resultsOnly = false): RecordedRoundResults
    {
        $envelope = $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: OfficialResultsFixture::ROUND_GROUP_A,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse($changes),
            resultsOnly: $resultsOnly,
        ));

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        assert($recorded instanceof RecordedRoundResults);

        return $recorded;
    }

    /**
     * @return array<string, array<string, mixed>> ref => the entry JSON
     */
    private function entries(): array
    {
        $entries = [];
        foreach (self::getContainer()->get(GetRoundResultEntries::class)->forRound(OfficialResultsFixture::ROUND_GROUP_A) as $entry) {
            $entries[$entry->ref->toString()] = $entry->jsonSerialize();
        }

        return $entries;
    }

    private function column(string $entryId): null|int
    {
        /** @var null|int $value */
        $value = $this->database->fetchOne('SELECT result_missing_pieces FROM competition_participant_round WHERE id = :id', ['id' => $entryId]);

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function change(string $entry, string $field, mixed $from, mixed $to): array
    {
        return ['clientChangeId' => Uuid::uuid7()->toString(), 'entry' => $entry, 'field' => $field, 'from' => $from, 'to' => $to];
    }

    /**
     * @return list<string>
     */
    private static function statuses(RecordedRoundResults $recorded): array
    {
        return array_map(static fn (RoundResultChangeOutcome $outcome): string => $outcome->status->value, $recorded->outcomes);
    }

    private static function anna(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_ANNA;
    }

    private static function filip(): string
    {
        return 'participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP;
    }
}
