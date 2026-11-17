<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleFormatRefused;
use SpeedPuzzling\Web\Message\EditCompetitionRound;
use SpeedPuzzling\Web\Message\RecordRoundResults;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Message\SetCompetitionRoundPuzzles;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRoundRepository;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Results\RecordedRoundResults;
use SpeedPuzzling\Web\Results\RoundResultChangeOutcome;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Services\RoundResultChangesParser;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The per-puzzle record of a `pick` round's entries through the one write path (competition toolkit A3,
 * A-round-formats.md §2.2-2.5, R-review-and-plan.md §1.1): Group B as "pick 2 of 3" - SECRET 1500 pieces, PUBLIC 2000,
 * the fixture's Puzzle 17 5000. Ivan finished in 5000 s without a record.
 */
final class RoundEntryPuzzlesRecordingTest extends KernelTestCase
{
    use RoundPuzzleFormatSetup;

    private const string IVAN = 'participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN;
    private const string HUGO = 'participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO;

    private MessageBusInterface $messageBus;
    private Connection $database;
    private string $big;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->big = self::addRoundPuzzles($this->database);
        self::makePick($this->database, 2);
    }

    public function testAResultAndItsPuzzlesAreRecordedTogetherAndCarriedByTheEntry(): void
    {
        $puzzles = [self::done(self::PUBLIC, 2710), self::done(self::SECRET, 4800)];

        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['seconds' => 4800]),
            self::change(self::IVAN, 'puzzles', null, $puzzles),
        ]);

        self::assertSame(['applied', 'applied'], self::statuses($recorded));
        self::assertSame($puzzles, $recorded->outcomes[1]->jsonSerialize()['current']);
        self::assertSame($puzzles, $this->storedPuzzles(OfficialResultsFixture::ENTRY_B_IVAN));

        $entry = $this->entry(self::IVAN);
        $json = $entry->jsonSerialize();
        self::assertSame($puzzles, $json['puzzles']);
        self::assertSame(['seconds' => 4800], $json['result']);
        // A record holds round puzzle ids only - never a puzzle's name, a secret one's least of all
        $encoded = json_encode($json, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET_NAME, $encoded);
        self::assertStringNotContainsString(self::PUBLIC_NAME, $encoded);
        // Its own puzzles: 2000 + 1500 pieces - the seed's share of an unfinished result
        self::assertSame(3500, $entry->piecesCount);
        // Without a record: the round's 2 biggest
        $hugo = $this->entry(self::HUGO);
        self::assertNull($hugo->jsonSerialize()['puzzles']);
        self::assertSame(7000, $hugo->piecesCount);
    }

    public function testATotalRoundsEntriesCarryNoPuzzlesKey(): void
    {
        $entries = self::getContainer()->get(GetRoundResultEntries::class)->forRound(OfficialResultsFixture::ROUND_GROUP_A);

        self::assertArrayNotHasKey('puzzles', $entries[0]->jsonSerialize());
        self::assertNull($entries[0]->piecesCount);
    }

    public function testAChoiceAloneWhileTheRoundRunsAndAReplayChangesNothing(): void
    {
        $this->clearIvan();
        $change = self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false]]);

        self::assertSame(['applied'], self::statuses($this->record([$change])));

        $replayed = $this->record([$change]);
        self::assertSame(['unchanged'], self::statuses($replayed));
        self::assertSame([['roundPuzzleId' => self::PUBLIC, 'done' => false]], $replayed->outcomes[0]->jsonSerialize()['current']);
        self::assertSame([], $replayed->changedEntryRefs);
    }

    public function testAPuzzlesChangeFromAnOutdatedValueIsAConflictWithWhatIsThereNow(): void
    {
        $this->clearIvan();
        $this->record([self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false]])]);

        $recorded = $this->record([self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::SECRET, 'done' => false]])]);

        self::assertSame(['conflict'], self::statuses($recorded));
        self::assertSame('changed_meanwhile', $recorded->outcomes[0]->reason);
        self::assertSame([['roundPuzzleId' => self::PUBLIC, 'done' => false]], $recorded->outcomes[0]->jsonSerialize()['current']);
    }

    public function testAResultAcceptedAloneIsRefusedWithItsRefusedPuzzles(): void
    {
        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['seconds' => 4800]),
            // Not a puzzle of this round
            self::change(self::IVAN, 'puzzles', null, [self::done(Uuid::uuid7()->toString()), self::done(self::SECRET)]),
        ]);

        self::assertSame(['rejected', 'rejected'], self::statuses($recorded));
        self::assertSame(['group_refused', 'unknown_round_puzzle'], self::reasons($recorded));
        self::assertSame([], $recorded->changedEntryRefs);
        self::assertSame(5000, $this->database->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_IVAN]));
    }

    public function testAConflictingResultTakesItsPuzzlesDown(): void
    {
        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 1234], ['seconds' => 4800]),
            self::change(self::IVAN, 'puzzles', null, [self::done(self::PUBLIC), self::done(self::SECRET)]),
        ]);

        self::assertSame(['conflict', 'rejected'], self::statuses($recorded));
        self::assertSame(['changed_meanwhile', 'group_refused'], self::reasons($recorded));
        self::assertNull($this->storedPuzzles(OfficialResultsFixture::ENTRY_B_IVAN));
    }

    public function testOtherEntriesOfTheSetAreNotPartOfTheGroup(): void
    {
        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 1234], ['seconds' => 4800]),
            self::change(self::HUGO, 'puzzles', null, [self::done(self::PUBLIC), self::done(self::SECRET)]),
        ]);

        self::assertSame(['conflict', 'applied'], self::statuses($recorded));
    }

    public function testAFinishedResultNeedsAllItsPuzzlesFinished(): void
    {
        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['seconds' => 4800]),
            // One of two
            self::change(self::IVAN, 'puzzles', null, [self::done(self::PUBLIC)]),
        ]);

        self::assertSame(['puzzles_mismatch', 'puzzles_mismatch'], self::reasons($recorded));

        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['seconds' => 4800]),
            // The last split is not the result's time
            self::change(self::IVAN, 'puzzles', null, [self::done(self::PUBLIC, 2000), self::done(self::SECRET, 4700)]),
        ]);

        self::assertSame(['puzzles_mismatch', 'puzzles_mismatch'], self::reasons($recorded));
        self::assertSame(5000, $this->database->fetchOne('SELECT result_seconds FROM competition_participant_round WHERE id = :id', ['id' => OfficialResultsFixture::ENTRY_B_IVAN]));
    }

    public function testAnUnfinishedResultIsTheSumOverItsPuzzles(): void
    {
        $puzzles = [self::done(self::PUBLIC, 2710), ['roundPuzzleId' => self::SECRET, 'done' => false, 'piecesPlaced' => 312]];

        $wrongSum = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['piecesPlaced' => 2000]),
            self::change(self::IVAN, 'puzzles', null, $puzzles),
        ]);
        self::assertSame(['puzzles_mismatch', 'puzzles_mismatch'], self::reasons($wrongSum));

        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['piecesPlaced' => 2312]),
            self::change(self::IVAN, 'puzzles', null, $puzzles),
        ]);
        self::assertSame(['applied', 'applied'], self::statuses($recorded));

        // An old cached page typing a total over the record: refused with the reason, the record stays
        $oldClient = $this->record([self::change(self::IVAN, 'result', ['piecesPlaced' => 2312], ['piecesPlaced' => 2400])]);
        self::assertSame(['puzzles_mismatch'], self::reasons($oldClient));
    }

    public function testDidNotStartHasNoPuzzles(): void
    {
        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['didNotStart' => true]),
            self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false]]),
        ]);

        self::assertSame(['puzzles_mismatch', 'puzzles_mismatch'], self::reasons($recorded));
    }

    public function testRules2To5ArePerChange(): void
    {
        $this->clearIvan();

        $recorded = $this->record([
            self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false], ['roundPuzzleId' => self::PUBLIC, 'done' => false]]),
            self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false], ['roundPuzzleId' => self::SECRET, 'done' => false], ['roundPuzzleId' => $this->big, 'done' => false]]),
            self::change(self::IVAN, 'puzzles', null, [self::done(self::PUBLIC, 0)]),
            self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false], self::done(self::SECRET)]),
            self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::SECRET, 'done' => false, 'piecesPlaced' => 1500]]),
            // Not readable: a split on a puzzle not done
            self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::SECRET, 'done' => false, 'atSeconds' => 10]]),
        ]);

        self::assertSame(
            ['puzzle_listed_twice', 'too_many_puzzles', 'invalid_split', 'splits_out_of_order', 'invalid_pieces_placed', 'invalid_change'],
            self::reasons($recorded),
        );
    }

    public function testAnUnfinishedResultWithoutARecordIsBoundByKTimesTheBiggestPuzzle(): void
    {
        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['piecesPlaced' => 10000]),
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['piecesPlaced' => 9999]),
        ]);

        self::assertSame(['invalid_pieces_placed', null], self::reasons($recorded));
    }

    public function testATotalRoundTakesNoPuzzles(): void
    {
        $recorded = $this->record(
            [self::change('participant_round:' . OfficialResultsFixture::ENTRY_A_FILIP, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false]])],
            OfficialResultsFixture::ROUND_GROUP_A,
        );

        self::assertSame(['puzzles_not_used'], self::reasons($recorded));
    }

    public function testARefereeRecordsThePuzzlesWithTheResult(): void
    {
        $recorded = $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['seconds' => 4800]),
            self::change(self::IVAN, 'puzzles', null, [self::done(self::PUBLIC), self::done(self::SECRET, 4800)]),
            self::change(self::IVAN, 'table_number', null, 5),
        ], resultsOnly: true);

        self::assertSame(['applied', 'applied', 'rejected'], self::statuses($recorded));
        self::assertSame('results_only', $recorded->outcomes[2]->reason);
    }

    public function testEveryNewReasonIsTranslated(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (['puzzles_not_used', 'unknown_round_puzzle', 'puzzle_listed_twice', 'too_many_puzzles', 'invalid_split', 'splits_out_of_order', 'puzzles_mismatch', 'group_refused'] as $reason) {
            $key = 'official_results.reason.' . $reason;
            self::assertNotSame($key, $translator->trans($key, locale: 'en'), $key);
        }

        foreach ([RoundPuzzleFormatRefused::PUZZLE_IN_RESULTS, RoundPuzzleFormatRefused::CONFLICTS_WITH_RESULTS] as $reason) {
            $refused = new RoundPuzzleFormatRefused($reason, 2, 3, 4);
            self::assertStringContainsString('4', $translator->trans($refused->translationKey(), $refused->translationParameters(), locale: 'en'));
        }
    }

    public function testARecordedChoiceIsOfficialData(): void
    {
        $this->clearIvan();
        self::assertFalse($this->ivanEntity()->hasOfficialData());

        $this->record([self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false]])]);

        self::assertTrue($this->ivanEntity()->hasOfficialData());
        $guard = self::getContainer()->get(OfficialResultsGuard::class);
        self::assertTrue($guard->participantHasOfficialData(OfficialResultsFixture::PARTICIPANT_IVAN, self::ROUND));
        self::assertContains(self::IVAN, array_map(static fn (RoundResultEntry $entry): string => $entry->ref->toString(), $guard->entriesWithOfficialData(self::ROUND)));
    }

    public function testAPuzzleARecordNamesIsNeverRemoved(): void
    {
        $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['seconds' => 4800]),
            self::change(self::IVAN, 'puzzles', null, [self::done(self::PUBLIC), self::done(self::SECRET)]),
        ]);
        $this->clearEntityManager();

        try {
            $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound(self::PUBLIC));
            self::fail('Refused expected');
        } catch (RoundPuzzleFormatRefused $refused) {
            self::assertSame(RoundPuzzleFormatRefused::PUZZLE_IN_RESULTS, $refused->reason);
            self::assertSame(1, $refused->entries);
        }

        $this->clearEntityManager();

        try {
            $this->messageBus->dispatch(new SetCompetitionRoundPuzzles(self::ROUND, [PuzzleFixture::PUZZLE_1500_01, PuzzleFixture::PUZZLE_5000]));
            self::fail('Refused expected');
        } catch (RoundPuzzleFormatRefused $refused) {
            self::assertSame(RoundPuzzleFormatRefused::PUZZLE_IN_RESULTS, $refused->reason);
            self::assertSame(1, $refused->entries);
        }

        $this->clearEntityManager();

        self::assertSame(3, $this->roundPuzzlesCount());

        // A puzzle nobody's record names may go
        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound($this->big));
        self::assertSame(2, $this->roundPuzzlesCount());
    }

    public function testTheFormatNeverChangesUnderARecord(): void
    {
        $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['seconds' => 4800]),
            self::change(self::IVAN, 'puzzles', null, [self::done(self::PUBLIC), self::done(self::SECRET)]),
        ]);

        foreach ([[RoundPuzzleFormat::Pick, 3], [RoundPuzzleFormat::Pick, 1], [RoundPuzzleFormat::Total, null]] as [$format, $toSolve]) {
            try {
                $this->editFormat($format, $toSolve);
                self::fail('Refused expected');
            } catch (RoundPuzzleFormatRefused $refused) {
                self::assertSame(RoundPuzzleFormatRefused::CONFLICTS_WITH_RESULTS, $refused->reason);
                self::assertSame(1, $refused->entries);
            }
        }

        self::assertSame(['pick', 2], [$this->database->fetchOne('SELECT puzzle_format FROM competition_round WHERE id = :id', ['id' => self::ROUND]), $this->database->fetchOne('SELECT puzzles_to_solve FROM competition_round WHERE id = :id', ['id' => self::ROUND])]);
    }

    public function testAChoiceStillOpenAllowsAHigherK(): void
    {
        $this->clearIvan();
        $this->record([self::change(self::IVAN, 'puzzles', null, [['roundPuzzleId' => self::PUBLIC, 'done' => false]])]);

        $this->editFormat(RoundPuzzleFormat::Pick, 3);

        self::assertSame(3, $this->database->fetchOne('SELECT puzzles_to_solve FROM competition_round WHERE id = :id', ['id' => self::ROUND]));
    }

    /**
     * A set with `puzzles` costs two reads more than the same set without (the round's format FOR SHARE, its puzzles FOR
     * KEY SHARE) - and a set of a `total` round none (RecordRoundResultsStatementPinTest).
     */
    public function testAPuzzlesSetReadsTheRoundsFormatUnderALock(): void
    {
        $this->clearEntityManager();
        /** @var DebugDataHolder $debugData */
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();

        $this->record([
            self::change(self::IVAN, 'result', ['seconds' => 5000], ['seconds' => 4800]),
            self::change(self::IVAN, 'puzzles', null, [self::done(self::PUBLIC), self::done(self::SECRET)]),
        ]);

        $sqls = [];
        foreach ($debugData->getData() as $queries) {
            foreach (is_array($queries) ? $queries : [] as $query) {
                $sqls[] = is_array($query) && is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }

        self::assertCount(1, array_filter($sqls, static fn (string $sql): bool => str_contains($sql, 'FOR SHARE') && str_contains($sql, 'competition_round')));
        self::assertCount(1, array_filter($sqls, static fn (string $sql): bool => str_contains($sql, 'FOR KEY SHARE OF crp')));
    }

    private function roundPuzzlesCount(): int
    {
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM competition_round_puzzle WHERE round_id = :round', ['round' => self::ROUND]);
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    private function clearIvan(): void
    {
        $this->record([self::change(self::IVAN, 'result', ['seconds' => 5000], null)]);
    }

    private function ivanEntity(): \SpeedPuzzling\Web\Entity\CompetitionParticipantRound
    {
        $this->clearEntityManager();
        $entity = self::getContainer()->get(CompetitionParticipantRoundRepository::class)->find(OfficialResultsFixture::ENTRY_B_IVAN);
        assert($entity !== null);

        return $entity;
    }

    private function entry(string $ref): RoundResultEntry
    {
        foreach (self::getContainer()->get(GetRoundResultEntries::class)->forRound(self::ROUND) as $entry) {
            if ($entry->ref->toString() === $ref) {
                return $entry;
            }
        }

        self::fail('No entry ' . $ref);
    }

    /**
     * @return null|list<array<string, mixed>>
     */
    private function storedPuzzles(string $entryId): null|array
    {
        $stored = $this->database->fetchOne('SELECT result_puzzles FROM competition_participant_round WHERE id = :id', ['id' => $entryId]);

        if (!is_string($stored)) {
            return null;
        }

        $value = \SpeedPuzzling\Web\Value\RoundEntryPuzzles::fromStored($stored);

        return $value?->toWire();
    }

    /**
     * @throws RoundPuzzleFormatRefused
     */
    private function editFormat(RoundPuzzleFormat $format, null|int $toSolve): void
    {
        $this->clearEntityManager();
        $round = self::getContainer()->get(CompetitionRoundRepository::class)->get(self::ROUND);

        $this->messageBus->dispatch(new EditCompetitionRound(
            roundId: self::ROUND,
            competitionId: $round->competition->id->toString(),
            name: $round->name,
            minutesLimit: $round->minutesLimit ?? 90,
            startsAt: $round->startsAt,
            timezone: $round->displayTimezone(),
            badgeBackgroundColor: $round->badgeBackgroundColor,
            badgeTextColor: $round->badgeTextColor,
            category: $round->category,
            keepFields: EditCompetitionRound::FIELDS,
            puzzleFormat: $format,
            puzzlesToSolve: $toSolve,
        ));
        $this->clearEntityManager();
    }

    private function clearEntityManager(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /**
     * @param list<array<string, mixed>> $changes wire format
     */
    private function record(array $changes, string $roundId = self::ROUND, bool $resultsOnly = false): RecordedRoundResults
    {
        $envelope = $this->messageBus->dispatch(new RecordRoundResults(
            competitionId: OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            roundId: $roundId,
            actingPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            changes: RoundResultChangesParser::parse($changes),
            resultsOnly: $resultsOnly,
        ));

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        assert($recorded instanceof RecordedRoundResults);
        $this->clearEntityManager();

        return $recorded;
    }

    /**
     * @return array{roundPuzzleId: string, done: true, atSeconds?: int}
     */
    private static function done(string $roundPuzzleId, null|int $atSeconds = null): array
    {
        return ['roundPuzzleId' => $roundPuzzleId, 'done' => true, ...($atSeconds !== null ? ['atSeconds' => $atSeconds] : [])];
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

    /**
     * @return list<null|string>
     */
    private static function reasons(RecordedRoundResults $recorded): array
    {
        return array_map(static fn (RoundResultChangeOutcome $outcome): null|string => $outcome->reason, $recorded->outcomes);
    }
}
