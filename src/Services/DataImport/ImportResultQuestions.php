<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\ResultDuplicatePrevention;
use SpeedPuzzling\Web\Entity\SuspiciousTimeConfirmation;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\ResultDuplicatePreventionRepository;
use SpeedPuzzling\Web\Repository\SuspiciousTimeConfirmationRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportedResult;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultKnownBy;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultStatus;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * **The one way a result of a spreadsheet becomes a result of the profile**
 * (docs/features/data-import/adding.md "A row, step by step", step 3): every question the import asks before it
 * writes, in their order, and then the writer. Two callers, the same code - a rule changed here is changed for both:
 *
 * - ImportRowAdder, for a row of an "Add" whose puzzle is a catalogue puzzle;
 * - UnlistedPairer, for an unlisted result whose puzzle the player paired with a catalogue puzzle
 *   (docs/features/data-import/unlisted-puzzles.md "Pairing") - with what the unlisted result kept of its row.
 *
 * ImportResultQuestionsParityTest runs the same cases through both and compares what came of them.
 *
 * The steps:
 *
 * 1. the row's own result is still there (`this_row`) / a row with the same source key made one in any import of the
 *    player (`earlier_import`) - the writer's own questions, asked first so the ones below are only asked of a result
 *    that would really be written;
 * 2. the player has the same result on the profile - day, time, solo/pair/team - and no row speaks for it (`profile`);
 * 3. the people fit the kind (`people_do_not_fit_type`);
 * 4. somebody else's pair/team result of that day and time (`somebody_elses_result`);
 * 5. a result on the profile that may be this one written differently (`similar_result_exists`);
 * 6. the time verification's judgement (`unusual_time`);
 * 7. the file says the result is on MySpeedPuzzling, and none was found (`said_already_on_profile`);
 * 8. ImportedResultWriter::write() - and with it: "these are two" recorded for the duplicate job
 *    (ResultDuplicatePrevention `saved_anyway`), "it is right" stored for the scan (SuspiciousTimeConfirmation), the
 *    wish list item the result removed told back.
 *
 * Questions 4-7 are waved through by the player's word on the row (`answers.addAnyway`, given for the row as it reads
 * and the puzzle it is paired with - DataImportRow::isWavedThrough()).
 *
 * Called inside a handler's transaction, under the player's import lock: it persists and never flushes.
 */
readonly final class ImportResultQuestions
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DataImportRowRepository $rowRepository,
        private ImportedResultWriter $writer,
        private ImportNeverTwice $neverTwice,
        private ImportRowTimeCheck $timeCheck,
        private ResultDuplicatePreventionRepository $duplicatePreventionRepository,
        private SuspiciousTimeConfirmationRepository $confirmationRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param string $day Y-m-d - only a result with an exact day gets here
     * @param null|int $seconds null = solved without a time
     * @param list<string> $people the other people, one per entry, guests every one (ImportRowAdder::people())
     * @param null|bool $firstTry what the file said
     * @param bool $saidAlreadyOnProfile the file says the result is on MySpeedPuzzling already
     * @param bool $countedOnItsOwn the player said of two equal rows "these are two"
     * @param null|string $hintedSolvingTimeId the result the pairing's hint names - it pairs off first
     *
     * @return array{refused: null|string, made: null|array{puzzleId: string, seconds: null|int, day: null|string, teamId: null|string}, alreadyHow: null|string, alreadyTimeId: null|string, removedWishListItem: null|array{id: string, puzzleId: string, addedAt: DateTimeImmutable}}
     *         `refused`: why not (ImportRowRefusal / ImportedResultRefusal); `made`: what the writer wrote
     *         (ImportRowAdder::resultFacts()) - `alreadyTimeId` is then the new result's id; `alreadyHow`: the result
     *         is the player's already - `this_row`, `earlier_import`, `profile` - and `alreadyTimeId` names it
     */
    public function ask(
        DataImportRow $row,
        Player $player,
        Puzzle $puzzle,
        string $sourceKey,
        string $day,
        null|int $seconds,
        PuzzlingType $type,
        array $people,
        null|bool $firstTry,
        null|string $comment,
        int $position,
        bool $saidAlreadyOnProfile,
        bool $countedOnItsOwn,
        null|string $hintedSolvingTimeId = null,
    ): array {
        $playerId = $player->id->toString();
        $puzzleId = $puzzle->id->toString();

        $known = $this->knownResult($row, $playerId, $sourceKey);

        if ($known !== null) {
            return self::answer(how: $known['how'], timeId: $known['id']);
        }

        $free = $this->neverTwice->freeResultsOnProfile($player, $puzzle);
        $equal = ImportNeverTwice::equal($free, $day, $seconds, $type, $hintedSolvingTimeId);

        if ($equal !== null) {
            return self::answer(how: 'profile', timeId: $equal);
        }

        // What the writer would build must be what the row says it is: a "pair" without a partner named would be
        // written as a solo result, on the solo leaderboard
        if (PuzzlingType::fromPuzzlersCount(count($people) + 1) !== $type) {
            return self::answer(refused: ImportRowRefusal::PeopleDoNotFitType->value);
        }

        if (
            $type !== PuzzlingType::Solo
            && $seconds !== null
            && $row->isWavedThrough(ImportRowRefusal::SomebodyElsesResult->value, $puzzleId) === false
            && $this->neverTwice->somebodyElseHasIt($player, $puzzle, $day, $seconds)
        ) {
            return self::answer(refused: ImportRowRefusal::SomebodyElsesResult->value);
        }

        if (
            $row->isWavedThrough(ImportRowRefusal::SimilarResultExists->value, $puzzleId) === false
            && ImportNeverTwice::similar($free, $day, $seconds) !== null
        ) {
            return self::answer(refused: ImportRowRefusal::SimilarResultExists->value);
        }

        // The time verification's own judgement, as the add form asks it: an unusual time needs the player's "it is
        // right" (README "Time verification") - and with it the result is added and the answer stored for the scan
        $unusual = $seconds === null
            ? null
            : $this->timeCheck->unusual($playerId, $puzzleId, $seconds, $type, count($people) + 1, $day);

        if ($unusual !== null && $row->isWavedThrough(ImportRowRefusal::UnusualTime->value, $puzzleId) === false) {
            return self::answer(refused: ImportRowRefusal::UnusualTime->value);
        }

        // A hint, not a rule: the file says the result is on MySpeedPuzzling and none was found - listed, not added
        if ($saidAlreadyOnProfile && $row->isWavedThrough(ImportRowRefusal::SaidAlreadyOnProfile->value, $puzzleId) === false) {
            return self::answer(refused: ImportRowRefusal::SaidAlreadyOnProfile->value);
        }

        $outcome = $this->writer->write(new ImportedResult(
            importRowId: $row->id->toString(),
            sourceKey: $sourceKey,
            playerId: $playerId,
            puzzleId: $puzzleId,
            seconds: $seconds,
            solvedDay: $day,
            timezone: ImportedResult::zoneOf($player),
            firstTry: $firstTry,
            groupPlayers: $people,
            comment: $comment,
            position: max(0, $position),
        ));

        if ($outcome->status === ImportedResultStatus::Refused) {
            return self::answer(refused: $outcome->refusal->value ?? ImportRowRefusal::NotReady->value);
        }

        if ($outcome->status === ImportedResultStatus::AlreadyAdded) {
            return self::answer(
                how: $outcome->knownBy === ImportedResultKnownBy::Row ? 'this_row' : 'earlier_import',
                timeId: $outcome->solvingTimeId,
            );
        }

        $solvingTime = $this->scheduledResult((string) $outcome->solvingTimeId);

        // Two equal rows the player confirmed as two: the form's "it's another solve, save it" - the daily duplicate
        // job takes such a result as confirmed real (writer.md "What 6b calls", step 5)
        if ($countedOnItsOwn) {
            $this->duplicatePreventionRepository->save(new ResultDuplicatePrevention(
                Uuid::uuid7(),
                $player,
                DuplicatePreventionKind::SavedAnyway,
                $solvingTime->id,
                $puzzle->id,
                $this->clock->now(),
                SolvingTimeSource::SpreadsheetImport,
            ));
        }

        // "It is right": stored the way the form's answer is, so the scan tells the moderator the player was asked
        if ($unusual !== null) {
            $this->confirmationRepository->save(new SuspiciousTimeConfirmation(
                id: Uuid::uuid7(),
                time: $solvingTime,
                player: $player,
                expectedSeconds: $unusual->expectedSeconds ?? 0,
                confirmedAt: $this->clock->now(),
            ));
        }

        $removed = $outcome->removedWishListItem === null ? null : [
            'id' => $outcome->removedWishListItem['id'],
            'puzzleId' => $outcome->removedWishListItem['puzzleId'],
            'addedAt' => $outcome->removedWishListItem['addedAt'],
        ];

        return self::answer(made: ImportRowAdder::resultFacts($solvingTime), timeId: $solvingTime->id->toString(), removed: $removed);
    }

    /**
     * The writer's own two questions (ImportedResultWriter, "never twice" steps 2 and 3), asked before the profile is
     * looked at: the result this row made that still exists, else a result a row with the same key made - in any of
     * the player's imports. The writer asks them again when it is called.
     *
     * @return null|array{id: string, how: string}
     */
    public function knownResult(DataImportRow $row, string $playerId, string $sourceKey): null|array
    {
        $linked = $this->rowRepository->linkedSolvingTimeId($row->id->toString());

        if ($linked !== null && $this->resultExists($linked)) {
            return ['id' => $linked, 'how' => 'this_row'];
        }

        foreach ($this->rowRepository->solvingTimeIdsOfSourceKey($playerId, $sourceKey, $row->id->toString()) as $known) {
            if ($this->resultExists($known)) {
                return ['id' => $known, 'how' => 'earlier_import'];
            }
        }

        return null;
    }

    private function resultExists(string $solvingTimeId): bool
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PuzzleSolvingTime && $entity->id->toString() === $solvingTimeId) {
                return true;
            }
        }

        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(time.id)')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.id = :id')
            ->setParameter('id', $solvingTimeId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * The result the writer just persisted - not in the database before the flush.
     */
    private function scheduledResult(string $solvingTimeId): PuzzleSolvingTime
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof PuzzleSolvingTime && $entity->id->toString() === $solvingTimeId) {
                return $entity;
            }
        }

        return $this->entityManager->find(PuzzleSolvingTime::class, $solvingTimeId)
            ?? throw new LogicException(sprintf('The result %s the writer added is not there.', $solvingTimeId));
    }

    /**
     * @param null|array{puzzleId: string, seconds: null|int, day: null|string, teamId: null|string} $made
     * @param null|array{id: string, puzzleId: string, addedAt: DateTimeImmutable} $removed
     *
     * @return array{refused: null|string, made: null|array{puzzleId: string, seconds: null|int, day: null|string, teamId: null|string}, alreadyHow: null|string, alreadyTimeId: null|string, removedWishListItem: null|array{id: string, puzzleId: string, addedAt: DateTimeImmutable}}
     */
    private static function answer(null|string $refused = null, null|array $made = null, null|string $how = null, null|string $timeId = null, null|array $removed = null): array
    {
        return ['refused' => $refused, 'made' => $made, 'alreadyHow' => $how, 'alreadyTimeId' => $timeId, 'removedWishListItem' => $removed];
    }
}
