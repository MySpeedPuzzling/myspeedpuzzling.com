<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CollectionItem;
use SpeedPuzzling\Web\Entity\DataImportPuzzle;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Events\PuzzleAddedToCollection;
use SpeedPuzzling\Web\Exceptions\PuzzleNotRevealedYet;
use SpeedPuzzling\Web\Query\GetImportPuzzleCandidates;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use SpeedPuzzling\Web\Services\Unlisted\UnlistedRowWriter;
use SpeedPuzzling\Web\Value\DataImport\ImportedResultRefusal;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\ImportPuzzleChoice;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * One queued row of an "Add" becomes what it says (docs/features/data-import/adding.md "A row, step by step"): a
 * result through ImportedResultWriter - never any other way -, a puzzle in the player's collection, a puzzle on their
 * wish list; or it turns out to be the player's already; or a rule refuses it and it waits again with the reason.
 *
 * Called inside a handler's transaction (ApplyImportRowHandler - one row, one transaction): it persists and never
 * flushes. **Everything that can refuse the row is checked before anything is created**, and a row is all or nothing:
 * a row that is a result and an owned puzzle whose result is refused adds no collection item either.
 *
 * Its first statement is the player's import lock (ImportWriteLock - the writer's own): no other row of the player is
 * written until this one is committed, so what "never twice" reads here is what holds when the row is written. Only a
 * row that is still queued in the batch is touched - a second delivery, a second worker or a second batch finds
 * nothing to do.
 */
readonly final class ImportRowAdder
{
    /** CollectionItem::$comment */
    private const int COLLECTION_COMMENT_MAX_LENGTH = 500;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportWriteLock $lock,
        private DataImportRowRepository $rowRepository,
        private ImportNeverTwice $neverTwice,
        private ImportResultQuestions $questions,
        private GetImportPuzzleCandidates $candidates,
        private SecretPuzzleAccess $secretPuzzleAccess,
        private CollectionItemRepository $collectionItemRepository,
        private WishListItemRepository $wishListItemRepository,
        private UnlistedRowWriter $unlistedRows,
        private ImportRowPeople $rowPeople,
        private ImportPartnerLimits $partnerLimits,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The first pass of a batch (README "Never twice", step 2 - the exact ones first): a row whose result the player
     * already has - the same day, time and solo/pair/team on the decided puzzle, not spoken for by another row - is
     * settled as "already yours" and speaks for that result. Everything else stays queued for add(). Done for all
     * rows before any is added, so "is there a result left that may be this row written differently?" does not
     * depend on the order of the rows.
     *
     * @return bool whether the row was settled
     */
    public function settle(string $rowId, string $batchId, string $playerId): bool
    {
        $this->lock->lockUntilCommit($playerId);
        $row = $this->queuedRow($rowId, $batchId, $playerId);

        if ($row === null || in_array($row->kind, [DataImportRowKind::Result, DataImportRowKind::Both], true) === false) {
            return false;
        }

        // A row that stands for another row's result is settled with that row, when it is added
        if ($row->sameAsRow !== null && $row->addAsSeparate === false) {
            return false;
        }

        $puzzle = $this->decidedPuzzle($row);
        $said = $this->resultSaid($row);

        // The exact ones first (README "Never twice", step 2): a row known to its month or year is compared in add(),
        // when every exact row of the batch has found its result - it never takes a result an exact row is
        if ($puzzle === null || $said === null || $said['precision'] !== DatePrecision::Day) {
            return false;
        }

        // The row's own result, or the same data from another import: the writer's part of "never twice" - add() asks
        if ($this->questions->knownResult($row, $playerId, $said['sourceKey']) !== null) {
            return false;
        }

        $equal = ImportNeverTwice::equal(
            $this->neverTwice->freeResultsOnProfile($row->import->player, $puzzle),
            $said['day'],
            $said['seconds'],
            $said['type'],
            $row->alreadyYoursSolvingTimeId?->toString(),
        );

        if ($equal === null || $row->kind === DataImportRowKind::Both) {
            // A row that is also an owned puzzle is finished by add(), which finds the same result again
            return false;
        }

        $row->alreadyYours('profile', Uuid::fromString($equal));

        return true;
    }

    /**
     * @return array{status: string, code: null|string, customValuesOf: list<string>}
     *         status: `added`, `already_yours`, `refused` (code = why), `skipped` (not queued in this batch any more);
     *         customValuesOf: the rows that were added here and carry values of the player's own fields (this row,
     *         and the row it stands behind when that one was added on the way)
     */
    public function add(string $rowId, string $batchId, string $playerId): array
    {
        $this->lock->lockUntilCommit($playerId);
        $row = $this->queuedRow($rowId, $batchId, $playerId);

        if ($row === null) {
            return self::outcome('skipped');
        }

        $player = $row->import->player;
        $importPuzzle = $row->importPuzzleId === null ? null : $this->entityManager->find(DataImportPuzzle::class, $row->importPuzzleId);
        $hasResult = in_array($row->kind, [DataImportRowKind::Result, DataImportRowKind::Both], true);
        $isOwned = in_array($row->kind, [DataImportRowKind::Owned, DataImportRowKind::Both], true);
        $isWish = $row->kind === DataImportRowKind::WishList;

        // Kept as the player's own unlisted puzzle: the row goes into the unlisted tables and nowhere else
        // (docs/features/data-import/unlisted-puzzles.md) - no catalogue puzzle, no result, nothing public
        if ($importPuzzle !== null && $importPuzzle->choice === ImportPuzzleChoice::Unlisted) {
            return $this->addUnlisted($row, $importPuzzle, $batchId, $player, $hasResult, $hasResult || $isOwned || $isWish);
        }

        // What was ready when the batch was made may not be any more: the decision taken back, the sheet parsed again

        $puzzle = $this->decidedPuzzle($row);

        if ($puzzle === null || $row->problems !== null || ($hasResult || $isOwned || $isWish) === false) {
            return $this->refuse($row, ImportRowRefusal::NotReady->value);
        }

        $now = $this->clock->now();

        // Still a puzzle the player may choose (hidden, secret, not theirs to see): the candidates' own rule. A puzzle
        // merged since the decision is the survivor already - the decision moves with the merge (puzzleMergedInto())
        if (isset($this->candidates->choosable([$puzzle->id->toString()], $playerId, $now)[$puzzle->id->toString()]) === false) {
            return $this->refuse($row, ImportRowRefusal::PuzzleNotAvailable->value);
        }

        try {
            $this->secretPuzzleAccess->assertPuzzleWritableBy($puzzle, $playerId);
        } catch (PuzzleNotRevealedYet) {
            return $this->refuse($row, ImportedResultRefusal::PuzzleNotRevealed->value);
        }

        $comment = self::text($row->parsed['comment'] ?? null);

        if ($hasResult === false && $comment !== null && mb_strlen($comment) > self::COLLECTION_COMMENT_MAX_LENGTH) {
            return $this->refuse($row, ImportRowRefusal::CommentTooLong->value);
        }

        $made = [];
        $alreadyHow = null;
        $alreadyTimeId = null;
        $removedWishListItems = [];
        $customValuesOf = [];

        // --- The result ---
        if ($hasResult) {
            $said = $this->resultSaid($row);

            if ($said === null) {
                return $this->refuse($row, ImportRowRefusal::NeedsExactDay->value);
            }

            $result = $this->resultOf($row, $batchId, $player, $puzzle, $said, $comment);

            if ($result['refused'] !== null) {
                return $this->refuse($row, $result['refused'], $result['customValuesOf']);
            }

            $customValuesOf = $result['customValuesOf'];

            if ($result['made'] !== null) {
                $made['result'] = $result['made'];
                $removedWishListItems = $result['removedWishListItems'];
            } else {
                $alreadyHow = $result['alreadyHow'];
                $alreadyTimeId = $result['alreadyTimeId'];
            }
        }

        // --- The puzzle in the collection ---
        $collectionItemId = null;

        if ($isOwned) {
            // The system collection has no uniqueness in the database - the check holds under the player's lock
            $existing = $this->collectionItemRepository->findByCollectionPlayerAndPuzzle(null, $player, $puzzle);

            if ($existing === null) {
                $item = new CollectionItem(
                    id: Uuid::uuid7(),
                    collection: null,
                    player: $player,
                    puzzle: $puzzle,
                    // A row that is also a result gave its comment to the result
                    comment: $hasResult ? null : $comment,
                    addedAt: $now,
                );

                // The constructor records PuzzleAddedToCollection; its one reaction removes a wish list item behind
                // Undo's back (RemoveFromWishListWhenAddedToCollection) - so the event is taken off and the player's
                // setting is honoured here, where it is written down (README D18). Anything else stays
                foreach ($item->popEvents() as $event) {
                    if ($event instanceof PuzzleAddedToCollection === false) {
                        $item->recordThat($event);
                    }
                }

                $this->collectionItemRepository->save($item);
                $collectionItemId = $item->id;
                $made['collection'] = ['collectionId' => null];

                $wish = $this->wishListItemRepository->findByPlayerIdAndPuzzleIdWithRemoveFlag($playerId, $puzzle->id->toString());

                if ($wish !== null && self::isRemoved($removedWishListItems, $wish->id->toString()) === false) {
                    $removedWishListItems[] = self::removedWish($wish->id->toString(), $puzzle->id->toString(), $wish->addedAt, 'collection');
                    $this->wishListItemRepository->delete($wish);
                }
            } else {
                $alreadyHow ??= 'owned';
            }
        }

        // --- The puzzle on the wish list ---
        $wishListItemId = null;

        if ($isWish) {
            if (
                $this->wishListItemRepository->findByPlayerAndPuzzle($player, $puzzle) !== null
                || $this->collectionItemRepository->findByPlayerAndPuzzle($playerId, $puzzle->id->toString()) !== []
                || $this->neverTwice->hasSolved($player, $puzzle)
            ) {
                // On the list already - or owned or solved: nothing to wish for
                $alreadyHow = 'owned';
            } else {
                $wish = new WishListItem(Uuid::uuid7(), $player, $puzzle, false, $now);
                $this->wishListItemRepository->save($wish);
                $wishListItemId = $wish->id;
                $made['wishList'] = true;
            }
        }

        if ($removedWishListItems !== []) {
            $made['removedWishListItems'] = $removedWishListItems;
        }

        if ($made === []) {
            $row->alreadyYours($alreadyHow ?? 'owned', $alreadyTimeId === null ? null : Uuid::fromString($alreadyTimeId));

            return self::outcome('already_yours', customValuesOf: $customValuesOf);
        }

        // The row's result was the player's already (only its puzzle was added): the row keeps speaking for that result
        $row->added(
            $now,
            $collectionItemId,
            $wishListItemId,
            $made,
            isset($made['result']) ? null : $alreadyHow,
            isset($made['result']) || $alreadyTimeId === null ? null : Uuid::fromString($alreadyTimeId),
        );

        // Values of the player's own fields - and the words that stood beside a time, which become private notes
        foreach (['custom', 'notes'] as $carried) {
            if (is_array($row->parsed[$carried] ?? null) && $row->parsed[$carried] !== []) {
                $customValuesOf[] = $row->id->toString();

                break;
            }
        }

        return self::outcome('added', customValuesOf: $customValuesOf);
    }

    /**
     * A row whose puzzle is kept as an unlisted puzzle: all of it is UnlistedRowWriter's - here only what belongs to
     * the batch: a row that is the same result as another row of the file lets that row go first (README D6).
     *
     * @return array{status: string, code: null|string, customValuesOf: list<string>}
     */
    private function addUnlisted(DataImportRow $row, DataImportPuzzle $importPuzzle, string $batchId, Player $player, bool $hasResult, bool $isData): array
    {
        if ($row->problems !== null || $isData === false) {
            return $this->refuse($row, ImportRowRefusal::NotReady->value);
        }

        $customValuesOf = [];
        $resultIsAnotherRows = false;
        $standingTimeId = null;

        if ($hasResult && $row->sameAsRow !== null && $row->addAsSeparate === false) {
            $standsFor = $this->standingRow($row);

            if ($standsFor->isQueuedIn($batchId)) {
                $customValuesOf = $this->add($standsFor->id->toString(), $batchId, $player->id->toString())['customValuesOf'];
            }

            if (in_array($standsFor->state, [DataImportRowState::Added, DataImportRowState::AlreadyYours], true) === false) {
                return $this->refuse($row, ImportRowRefusal::EqualRowNotAdded->value, $customValuesOf);
            }

            $resultIsAnotherRows = true;
            $standingTimeId = $standsFor->state === DataImportRowState::AlreadyYours ? $standsFor->alreadyYoursSolvingTimeId : null;
        }

        $now = $this->clock->now();
        $written = $this->unlistedRows->write($row, $importPuzzle, $player, $resultIsAnotherRows, $now);

        if ($written['refused'] !== null) {
            return $this->refuse($row, $written['refused'], $customValuesOf);
        }

        $alreadyTimeId = $written['alreadyTimeId'] !== null ? Uuid::fromString($written['alreadyTimeId']) : $standingTimeId;

        if ($written['made'] === null) {
            $row->alreadyYours($written['alreadyHow'] ?? 'owned', $alreadyTimeId);

            return self::outcome('already_yours', customValuesOf: $customValuesOf);
        }

        // The values of the player's own fields have nothing to stand on yet: an unlisted result is no result
        $row->added($now, null, null, ['unlisted' => $written['made']], $written['alreadyHow'], $written['alreadyHow'] === null ? null : $alreadyTimeId);

        return self::outcome('added', customValuesOf: $customValuesOf);
    }

    /**
     * The result part of a row: already the player's (how, which result), refused (why), or made by the writer.
     *
     * @param array{day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType, sourceKey: string, people: list<string>, registeredPlayerIds: list<string>} $said
     *
     * @return array{refused: null|string, made: null|array<string, mixed>, alreadyHow: null|string, alreadyTimeId: null|string, removedWishListItems: list<array<string, mixed>>, customValuesOf: list<string>}
     */
    private function resultOf(DataImportRow $row, string $batchId, Player $player, Puzzle $puzzle, array $said, null|string $comment): array
    {
        $playerId = $player->id->toString();

        // Equal rows are one result unless the player says two (README D6): the row that stands for them is added
        // (first, if its turn has not come yet) and this one is that result
        if ($row->sameAsRow !== null && $row->addAsSeparate === false) {
            $standsFor = $this->standingRow($row);

            $customValuesOf = [];

            if ($standsFor->isQueuedIn($batchId)) {
                $customValuesOf = $this->add($standsFor->id->toString(), $batchId, $playerId)['customValuesOf'];
            }

            $timeId = match ($standsFor->state) {
                DataImportRowState::Added => $standsFor->solvingTimeId,
                DataImportRowState::AlreadyYours => $standsFor->alreadyYoursSolvingTimeId,
                default => null,
            };

            if ($timeId === null) {
                return self::answer(refused: ImportRowRefusal::EqualRowNotAdded->value, customValuesOf: $customValuesOf);
            }

            return self::answer(how: 'equal_row', timeId: $timeId->toString(), customValuesOf: $customValuesOf);
        }

        // "Sept 2020" on one sheet and "14 Sep 2020" on another, the same time: the finer row may stand later in the
        // file - it is added first, so that the rows' order changes nothing (its result is not in the database before
        // this transaction ends - the row itself says what it became). The batch's own part of the questions below
        $customValuesOf = [];
        $finerRowsResult = function () use ($row, $batchId, $playerId, $said, &$customValuesOf): null|string {
            foreach ($this->finerQueuedRows($row, $batchId, $said) as $finerRow) {
                $customValuesOf = $this->add($finerRow->id->toString(), $batchId, $playerId)['customValuesOf'];
                $finerTimeId = match ($finerRow->state) {
                    DataImportRowState::Added => $finerRow->solvingTimeId,
                    DataImportRowState::AlreadyYours => $finerRow->alreadyYoursSolvingTimeId,
                    default => null,
                };

                if ($finerTimeId !== null) {
                    return $finerTimeId->toString();
                }
            }

            return null;
        };

        // Every question the import asks before a result is written, and the writer - the one way a result of a
        // spreadsheet reaches the profile, shared with the pairing of unlisted puzzles (ImportResultQuestions)
        $asked = $this->questions->ask(
            row: $row,
            player: $player,
            puzzle: $puzzle,
            sourceKey: $said['sourceKey'],
            day: $said['day'],
            seconds: $said['seconds'],
            type: $said['type'],
            people: $said['people'],
            firstTry: is_bool($row->parsed['firstTry'] ?? null) ? $row->parsed['firstTry'] : null,
            comment: $comment,
            position: $row->rowNumber,
            saidAlreadyOnProfile: ($row->parsed['alreadyOnMsp'] ?? null) === true,
            countedOnItsOwn: $row->addAsSeparate,
            precision: $said['precision'],
            hintedSolvingTimeId: $row->alreadyYoursSolvingTimeId?->toString(),
            finerRowsResult: $finerRowsResult,
            // Asked last, of a result that would really be written: a registered partner is never flooded
            lastCheck: fn (): null|string => $this->partnerLimits->refusal($player, $batchId, $said['registeredPlayerIds'], $this->clock->now()),
        );

        if ($asked['refused'] !== null) {
            return self::answer(refused: $asked['refused']);
        }

        if ($asked['made'] === null) {
            return self::answer(how: $asked['alreadyHow'], timeId: $asked['alreadyTimeId'], customValuesOf: $customValuesOf);
        }

        $removed = [];

        if ($asked['removedWishListItem'] !== null) {
            $removed[] = self::removedWish($asked['removedWishListItem']['id'], $asked['removedWishListItem']['puzzleId'], $asked['removedWishListItem']['addedAt'], 'solved');
        }

        return self::answer(made: $asked['made'], removed: $removed);
    }

    /**
     * @param null|array<string, mixed> $made
     * @param list<array<string, mixed>> $removed
     * @param list<string> $customValuesOf
     *
     * @return array{refused: null|string, made: null|array<string, mixed>, alreadyHow: null|string, alreadyTimeId: null|string, removedWishListItems: list<array<string, mixed>>, customValuesOf: list<string>}
     */
    private static function answer(
        null|string $refused = null,
        null|array $made = null,
        null|string $how = null,
        null|string $timeId = null,
        array $removed = [],
        array $customValuesOf = [],
    ): array {
        return [
            'refused' => $refused,
            'made' => $made,
            'alreadyHow' => $how,
            'alreadyTimeId' => $timeId,
            'removedWishListItems' => $removed,
            'customValuesOf' => $customValuesOf,
        ];
    }

    /**
     * What an Undo compares a result with to tell whether somebody changed it since the import: the puzzle, the time,
     * the day and the people (a pair/team is the exact set of people - other people, another team).
     *
     * @return array{puzzleId: string, seconds: null|int, day: null|string, teamId: null|string}
     */
    public static function resultFacts(PuzzleSolvingTime $solvingTime): array
    {
        return [
            'puzzleId' => $solvingTime->puzzle->id->toString(),
            'seconds' => $solvingTime->secondsToSolve,
            'day' => $solvingTime->finishedAt?->format('Y-m-d'),
            'teamId' => $solvingTime->puzzlingTeam?->id->toString(),
        ];
    }

    private function queuedRow(string $rowId, string $batchId, string $playerId): null|DataImportRow
    {
        $row = $this->rowRepository->find($rowId);

        if ($row === null) {
            return null;
        }

        if ($row->import->isOwnedBy(strtolower($playerId)) === false) {
            throw new LogicException(sprintf('Import row %s is not of player %s.', $rowId, $playerId));
        }

        return $row->isQueuedIn($batchId) ? $row : null;
    }

    /**
     * The catalogue puzzle the row's distinct puzzle is decided to be - as the decision stands now.
     */
    private function decidedPuzzle(DataImportRow $row): null|Puzzle
    {
        if ($row->importPuzzleId === null) {
            return null;
        }

        $importPuzzle = $this->entityManager->find(DataImportPuzzle::class, $row->importPuzzleId);

        if ($importPuzzle === null || $importPuzzle->choice !== ImportPuzzleChoice::Catalogue) {
            return null;
        }

        return $importPuzzle->puzzle;
    }

    /**
     * What the row says of its result - a result with a date: a day, or the month or year the file knows, which is
     * stored as that (README D13: a date we do not know is never stored as a day). `day` is then the first day of
     * the period. A result without any date is not added.
     *
     * The people are the row's as the import's people are decided **now** (ImportRowPeople): a registered player
     * for a code that resolved or a name the importing player mapped, a guest for everybody else, the importing
     * player themself left out - and the kind with them.
     *
     * @return null|array{day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType, sourceKey: string, people: list<string>, registeredPlayerIds: list<string>}
     */
    private function resultSaid(DataImportRow $row): null|array
    {
        $parsed = $row->parsed ?? [];
        $keys = is_array($parsed['keys'] ?? null) ? $parsed['keys'] : [];
        $sourceKey = is_string($keys['result'] ?? null) ? $keys['result'] : $row->sourceKey;
        $type = is_string($parsed['type'] ?? null) ? PuzzlingType::tryFrom($parsed['type']) : null;
        $dated = self::dateSaid($parsed);

        if ($dated === null || $sourceKey === null || $sourceKey === '' || $type === null) {
            return null;
        }

        $seconds = is_int($parsed['seconds'] ?? null) && $parsed['seconds'] > 0 ? $parsed['seconds'] : null;

        $people = $this->rowPeople->of($row);

        return [
            'day' => $dated['day'],
            'precision' => $dated['precision'],
            'seconds' => $seconds,
            'type' => ImportRowPeople::typeWithout($type, $people['named'], count($people['people'])),
            'sourceKey' => $sourceKey,
            'people' => $people['people'],
            'registeredPlayerIds' => $people['registeredPlayerIds'],
        ];
    }

    /**
     * The rows still queued in the batch that say the same result as this month- or year-row and know more of its
     * date: the same distinct puzzle, time and solo/pair/team, a date inside this row's period.
     *
     * @param array{day: string, precision: DatePrecision, seconds: null|int, type: PuzzlingType, sourceKey: string, people: list<string>, registeredPlayerIds: list<string>} $said
     *
     * @return list<DataImportRow>
     */
    private function finerQueuedRows(DataImportRow $row, string $batchId, array $said): array
    {
        if ($row->importPuzzleId === null) {
            return [];
        }

        $finer = [];

        foreach ($this->rowRepository->queuedResultRowsOfPuzzle($batchId, $row->importPuzzleId, $row->id->toString()) as $other) {
            $otherSaid = $this->resultSaid($other);

            if (
                $otherSaid !== null
                && $otherSaid['seconds'] === $said['seconds']
                && $otherSaid['type'] === $said['type']
                && $otherSaid['precision'] !== $said['precision']
                && ($otherSaid['precision'] === DatePrecision::Day || $said['precision'] === DatePrecision::Year)
                && $said['precision']->holds(new DateTimeImmutable($said['day']), new DateTimeImmutable($otherSaid['day']))
            ) {
                $finer[] = $other;
            }
        }

        return $finer;
    }

    /**
     * The date of a parsed row (`parsed.date` is written at its precision: `2021-03-15`, `2021-03`, `2021`) as the
     * day it is stored on - the first day of a month or a year - and how exactly it is known.
     *
     * @param array<mixed> $parsed `data_import_row.parsed`
     *
     * @return null|array{day: string, precision: DatePrecision}
     */
    public static function dateSaid(array $parsed): null|array
    {
        $date = $parsed['date'] ?? null;
        $precision = is_string($parsed['datePrecision'] ?? null) ? DatePrecision::tryFrom($parsed['datePrecision']) : null;

        if ($precision === null || is_string($date) === false) {
            return null;
        }

        [$pattern, $rest] = match ($precision) {
            DatePrecision::Day => ['/^\d{4}-\d{2}-\d{2}$/', ''],
            DatePrecision::Month => ['/^\d{4}-\d{2}$/', '-01'],
            DatePrecision::Year => ['/^\d{4}$/', '-01-01'],
        };

        if (preg_match($pattern, $date) !== 1) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date . $rest);

        if ($day === false || $day->format('Y-m-d') !== $date . $rest) {
            return null;
        }

        return ['day' => $date . $rest, 'precision' => $precision];
    }

    /**
     * The row that stands for a group of equal rows: at most two steps away (an equal row of its own sheet, then the
     * same row on an earlier sheet).
     */
    private function standingRow(DataImportRow $row): DataImportRow
    {
        $standing = $row;

        for ($step = 0; $step < 4 && $standing->sameAsRow !== null && $standing->addAsSeparate === false; $step++) {
            $standing = $standing->sameAsRow;
        }

        return $standing;
    }

    /**
     * @param list<string> $customValuesOf
     *
     * @return array{status: string, code: null|string, customValuesOf: list<string>}
     */
    private function refuse(DataImportRow $row, string $code, array $customValuesOf = []): array
    {
        $row->refused($code);

        return self::outcome('refused', $code, $customValuesOf);
    }

    /**
     * @param list<string> $customValuesOf
     *
     * @return array{status: string, code: null|string, customValuesOf: list<string>}
     */
    private static function outcome(string $status, null|string $code = null, array $customValuesOf = []): array
    {
        return ['status' => $status, 'code' => $code, 'customValuesOf' => $customValuesOf];
    }

    /**
     * The other people of a row **as guests, every one of them** - what the unlisted puzzles keep and pair with
     * (UnlistedRowWriter, UnlistedPairer: an unlisted result is private, and its pairing writes guests). A row of
     * an "Add" does not come through here: its people are ImportRowPeople's - a code that resolved or a name the
     * importing player mapped is that player (docs/features/data-import/people.md). No entry leaves here with a
     * `#`, so the writer never resolves a code for these.
     *
     * @return list<string> one guest's name per entry
     */
    public static function people(mixed $people): array
    {
        if (is_array($people) === false) {
            return [];
        }

        $named = [];

        foreach ($people as $person) {
            $name = is_string($person) ? trim($person, "# \t\n\r\0\x0B") : '';

            if ($name !== '') {
                $named[] = $name;
            }
        }

        return array_values(array_unique($named));
    }

    private static function text(mixed $value): null|string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param list<array<string, mixed>> $removed
     */
    private static function isRemoved(array $removed, string $wishListItemId): bool
    {
        foreach ($removed as $item) {
            if (($item['id'] ?? null) === $wishListItemId) {
                return true;
            }
        }

        return false;
    }

    /**
     * A wish list item the row removed by the player's own setting - all Undo needs to put it back.
     *
     * @return array{id: string, puzzleId: string, addedAt: string, removeOnCollectionAdd: bool, why: string}
     */
    private static function removedWish(string $id, string $puzzleId, DateTimeImmutable $addedAt, string $why): array
    {
        return [
            'id' => $id,
            'puzzleId' => $puzzleId,
            'addedAt' => $addedAt->format('Y-m-d H:i:s'),
            // Only an item with the flag is ever removed (findByPlayerIdAndPuzzleIdWithRemoveFlag)
            'removeOnCollectionAdd' => true,
            'why' => $why,
        ];
    }
}
