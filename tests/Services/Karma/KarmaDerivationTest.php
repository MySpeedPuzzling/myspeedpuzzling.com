<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Karma;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleMergeAudit;
use SpeedPuzzling\Web\Entity\PuzzleMergeRequest;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Query\GetPlayerKarma;
use SpeedPuzzling\Web\Results\KarmaItem;
use SpeedPuzzling\Web\Services\Karma\KarmaRecalculator;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\KarmaKind;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The karma rule, one case at a time (docs/features/karma.md). Every test works on players of its own, so the
 * fixtures' requests and decisions never count in.
 */
final class KarmaDerivationTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Connection $database;
    private GetPlayerKarma $getPlayerKarma;
    private KarmaRecalculator $recalculator;
    private int $sequence = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->database = $container->get(Connection::class);
        $this->getPlayerKarma = $container->get(GetPlayerKarma::class);
        $this->recalculator = $container->get(KarmaRecalculator::class);
    }

    public function testOnlyAnApprovedChangeRequestCountsForItsProposer(): void
    {
        $proposer = $this->player();
        $moderator = $this->player();
        $puzzle = $this->puzzle(null, ean: null);

        $this->changeRequest($puzzle, $proposer, 'approved', $moderator);
        $this->changeRequest($puzzle, $proposer, 'rejected', $moderator);
        $this->changeRequest($puzzle, $proposer, 'pending');
        // Something else already did what it asked - nobody accepted it
        $this->changeRequest($puzzle, $proposer, 'outdated');

        self::assertSame(1, $this->karmaOf($proposer));
        self::assertSame(1, $this->getPlayerKarma->breakdown($this->id($proposer))->of(KarmaKind::ChangeRequest));
    }

    public function testOnlyAnApprovedMergeRequestCountsForItsReporter(): void
    {
        $reporter = $this->player();
        $moderator = $this->player();
        $puzzle = $this->puzzle(null, ean: null);

        $this->mergeRequest($puzzle, $reporter, 'approved', $moderator);
        $this->mergeRequest($puzzle, $reporter, 'rejected', $moderator);
        $this->mergeRequest($puzzle, $reporter, 'outdated');

        self::assertSame(1, $this->karmaOf($reporter));
    }

    public function testANewPuzzleCountsOnlyWithAnEanAndOnceApproved(): void
    {
        $adder = $this->player();

        $this->puzzle($adder, ean: '4005556123452');
        $this->puzzle($adder, ean: null);
        // A catalogue number typed into the EAN field is not a barcode
        $this->puzzle($adder, ean: 'A-123');
        $this->puzzle($adder, ean: '4005556123453', approved: false);

        self::assertSame(1, $this->karmaOf($adder));
        self::assertSame(1, $this->getPlayerKarma->breakdown($this->id($adder))->of(KarmaKind::PuzzleAdded));
    }

    public function testOfMergedCopiesOnlyTheEarliestAddedKeepsTheCredit(): void
    {
        $first = $this->player();
        $later = $this->player();

        // The later copy survived the merge, the earlier one was merged away into it
        $survivor = $this->puzzle($later, ean: '4005556123452', addedAt: new DateTimeImmutable('2026-05-01 10:00'));
        $mergedAway = $this->mergedAwayPuzzle($first, new DateTimeImmutable('2026-03-01 10:00'), 'The first copy', $survivor);

        self::assertSame(1, $this->karmaOf($first));
        self::assertSame(0, $this->karmaOf($later));

        // The survivor's adder sees why it does not count
        $laterItems = $this->getPlayerKarma->items($this->id($later), 50, 0);
        self::assertCount(1, $laterItems);
        self::assertSame(KarmaKind::DuplicatePuzzle, $laterItems[0]->kind);
        self::assertFalse($laterItems[0]->counts());

        $firstItems = $this->getPlayerKarma->items($this->id($first), 50, 0);
        self::assertSame(KarmaKind::PuzzleAdded, $firstItems[0]->kind);
        self::assertSame($survivor->id->toString(), $firstItems[0]->puzzleId);
        self::assertNotSame($mergedAway, $firstItems[0]->puzzleId);
    }

    public function testAMergedAwayLaterCopyEarnsNothing(): void
    {
        $first = $this->player();
        $later = $this->player();

        $survivor = $this->puzzle($first, ean: '4005556123452', addedAt: new DateTimeImmutable('2026-03-01 10:00'));
        $this->mergedAwayPuzzle($later, new DateTimeImmutable('2026-05-01 10:00'), 'The second copy', $survivor);

        self::assertSame(1, $this->karmaOf($first));
        self::assertSame(0, $this->karmaOf($later));

        $laterItems = $this->getPlayerKarma->items($this->id($later), 50, 0);
        self::assertCount(1, $laterItems);
        self::assertSame(KarmaKind::DuplicatePuzzle, $laterItems[0]->kind);
        self::assertSame('The second copy', $laterItems[0]->puzzleName);
        self::assertSame(0, $this->getPlayerKarma->breakdown($this->id($later))->total());
        self::assertSame(1, $this->getPlayerKarma->breakdown($this->id($later))->items());
    }

    public function testEveryModeratorDecisionAndDirectEditCounts(): void
    {
        $moderator = $this->player();
        $proposer = $this->player();
        $puzzle = $this->puzzle(null, ean: null);

        $approved = $this->changeRequest($puzzle, $proposer, 'approved', $moderator);
        $rejected = $this->changeRequest($puzzle, $proposer, 'rejected', $moderator);
        $this->decision($moderator, PuzzleModerationAction::ChangeRequestApproved, changeRequest: $approved);
        $this->decision($moderator, PuzzleModerationAction::ChangeRequestRejected, changeRequest: $rejected);
        $this->decision($moderator, PuzzleModerationAction::MergeRequestRejected);
        $this->decision($moderator, PuzzleModerationAction::PuzzleApproved, puzzle: $puzzle);
        $this->decision($moderator, PuzzleModerationAction::PuzzleEdited, puzzle: $puzzle);
        $this->decision($moderator, PuzzleModerationAction::PuzzleEdited, puzzle: $puzzle);

        // Not a person's work in the queues: brands, the internal API
        $this->decision($moderator, PuzzleModerationAction::BrandApproved);
        $this->decision($moderator, PuzzleModerationAction::BrandMerged);
        $this->decision($moderator, PuzzleModerationAction::ChangeRequestApproved, source: MergeDecisionSource::InternalApi);

        self::assertSame(6, $this->karmaOf($moderator));
        $breakdown = $this->getPlayerKarma->breakdown($this->id($moderator));
        self::assertSame(4, $breakdown->of(KarmaKind::Decision));
        self::assertSame(2, $breakdown->of(KarmaKind::DirectEdit));
    }

    public function testDecisionsWithoutAPersonCountForNobody(): void
    {
        $this->recalculator->recalculateAll();

        // The application closing a request by itself
        $this->entityManager->persist(new PuzzleModerationDecision(
            id: Uuid::uuid7(),
            action: PuzzleModerationAction::ChangeRequestOutdated,
            decidedAt: new DateTimeImmutable('2026-09-01 10:00'),
            decidedById: null,
            decidedByName: null,
            decidedByCode: null,
            source: MergeDecisionSource::Automatic,
        ));
        $this->entityManager->flush();

        self::assertSame(0, $this->recalculator->recalculateAll());
    }

    public function testAModeratorDecidingTheirOwnProposalGetsOneInTotal(): void
    {
        $moderator = $this->player();
        $puzzle = $this->puzzle(null, ean: null);

        $ownApproved = $this->changeRequest($puzzle, $moderator, 'approved', $moderator);
        $this->decision($moderator, PuzzleModerationAction::ChangeRequestApproved, changeRequest: $ownApproved);

        self::assertSame(1, $this->karmaOf($moderator));

        // Declining one's own: the decision is the one that counts
        $ownRejected = $this->changeRequest($puzzle, $moderator, 'rejected', $moderator);
        $this->decision($moderator, PuzzleModerationAction::ChangeRequestRejected, changeRequest: $ownRejected);

        self::assertSame(2, $this->karmaOf($moderator));

        // Their own merge report, merged by themselves (the approval queue's "merge")
        $ownMerge = $this->mergeRequest($puzzle, $moderator, 'approved', $moderator);
        $this->decision($moderator, PuzzleModerationAction::MergeRequestApproved, mergeRequest: $ownMerge);

        self::assertSame(3, $this->karmaOf($moderator));

        // Approving a puzzle they added themselves, with an EAN
        $ownPuzzle = $this->puzzle($moderator, ean: '4005556123452');
        $this->decision($moderator, PuzzleModerationAction::PuzzleApproved, puzzle: $ownPuzzle);

        self::assertSame(4, $this->karmaOf($moderator));
    }

    public function testAScannedEanCountsOnceEvenWithTheBackfilledDecisionRow(): void
    {
        $scanner = $this->player();
        $puzzle = $this->puzzle(null, ean: null);

        // LinkEanToPuzzleHandler: an already approved request, approved by its own reporter in the same instant
        $scan = $this->changeRequest($puzzle, $scanner, 'approved', $scanner);
        // The 2026-09-25 backfill logged it as if a moderator had decided it
        $this->decision($scanner, PuzzleModerationAction::ChangeRequestApproved, changeRequest: $scan);

        self::assertSame(1, $this->karmaOf($scanner));
        self::assertSame(0, $this->getPlayerKarma->breakdown($this->id($scanner))->of(KarmaKind::Decision));
    }

    public function testAdminsHaveNoKarma(): void
    {
        $admin = $this->player();
        $this->database->executeStatement('UPDATE player SET is_admin = true WHERE id = :id', ['id' => $this->id($admin)]);
        $proposer = $this->player();
        $puzzle = $this->puzzle($admin, ean: '4005556123452');

        $request = $this->changeRequest($puzzle, $admin, 'approved', $admin);
        $other = $this->changeRequest($puzzle, $proposer, 'approved', $admin);
        $this->decision($admin, PuzzleModerationAction::ChangeRequestApproved, changeRequest: $other);
        $this->decision($admin, PuzzleModerationAction::PuzzleEdited, puzzle: $puzzle);

        self::assertSame(0, $this->karmaOf($admin));
        self::assertSame([], $this->getPlayerKarma->items($this->id($admin), 50, 0));
        self::assertSame(0, $this->getPlayerKarma->breakdown($this->id($admin))->items());
        // The proposer of the admin's decision still gets theirs
        self::assertSame(1, $this->karmaOf($proposer));
        self::assertSame('approved', $this->database->fetchOne('SELECT status FROM puzzle_change_request WHERE id = :id', ['id' => $request->id->toString()]));
    }

    public function testTheRecalculationIsIdempotentAndMatchesTheHistory(): void
    {
        $adder = $this->player();
        $this->puzzle($adder, ean: '4005556123452');
        $this->puzzle($adder, ean: '4005556123453');

        $this->recalculator->recalculateAll();
        self::assertSame(0, $this->recalculator->recalculateAll(), 'A second run changes nothing');

        self::assertSame(2, $this->storedKarma($adder));
        self::assertSame(2, $this->getPlayerKarma->breakdown($this->id($adder))->total());

        // The scoped run agrees with the full one
        $this->database->executeStatement('UPDATE player SET karma = 0 WHERE id = :id', ['id' => $this->id($adder)]);
        self::assertSame(1, $this->recalculator->recalculateFor([$this->id($adder), 'not-a-uuid']));
        self::assertSame(2, $this->storedKarma($adder));
        self::assertSame(0, $this->recalculator->recalculateFor([$this->id($adder)]));
    }

    public function testTheHistoryIsNewestFirstAndLinksThePuzzle(): void
    {
        $proposer = $this->player();
        $moderator = $this->player();
        $puzzle = $this->puzzle(null, ean: null);

        $this->changeRequest($puzzle, $proposer, 'approved', $moderator, reviewedAt: new DateTimeImmutable('2026-01-01 10:00'));
        $this->changeRequest($puzzle, $proposer, 'approved', $moderator, reviewedAt: new DateTimeImmutable('2026-06-01 10:00'));

        $items = $this->getPlayerKarma->items($this->id($proposer), 50, 0);
        self::assertCount(2, $items);
        self::assertSame('2026-06-01', $items[0]->at?->format('Y-m-d'));
        self::assertSame($puzzle->id->toString(), $items[0]->puzzleId);
        self::assertSame(array_map(static fn (KarmaItem $item): bool => $item->counts(), $items), [true, true]);

        self::assertCount(1, $this->getPlayerKarma->items($this->id($proposer), 1, 1));
    }

    private function karmaOf(Player $player): int
    {
        $this->recalculator->recalculateFor([$this->id($player)]);

        return $this->storedKarma($player);
    }

    private function storedKarma(Player $player): int
    {
        $karma = $this->database->fetchOne('SELECT karma FROM player WHERE id = :id', ['id' => $this->id($player)]);
        self::assertIsNumeric($karma);

        return (int) $karma;
    }

    private function id(Player $player): string
    {
        return $player->id->toString();
    }

    private function player(): Player
    {
        $this->sequence++;
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'karma' . $this->sequence . substr(Uuid::uuid4()->toString(), 0, 6),
            userId: null,
            name: 'Karma tester ' . $this->sequence,
            registeredAt: new DateTimeImmutable('2025-01-01 10:00'),
        );
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    private function puzzle(null|Player $addedBy, null|string $ean, bool $approved = true, null|DateTimeImmutable $addedAt = null): Puzzle
    {
        $this->sequence++;
        $puzzle = new Puzzle(
            id: Uuid::uuid7(),
            piecesCount: 500,
            name: 'Karma puzzle ' . $this->sequence,
            approved: $approved,
            addedByUser: $addedBy,
            addedAt: $addedAt ?? new DateTimeImmutable('2026-02-01 10:00'),
            eans: EanList::fromStored($ean),
        );
        $this->entityManager->persist($puzzle);
        $this->entityManager->flush();

        // A placeholder typed into the EAN field stays as typed - the parser would not keep it
        if ($ean !== null && EanList::fromStored($ean)->toStored() !== $ean) {
            $this->database->executeStatement('UPDATE puzzle SET ean = :ean WHERE id = :id', ['ean' => $ean, 'id' => $puzzle->id->toString()]);
        }

        return $puzzle;
    }

    /**
     * A puzzle a merge deleted: only the audit's snapshot remembers it (and the redirect to the survivor)
     */
    private function mergedAwayPuzzle(Player $addedBy, DateTimeImmutable $addedAt, string $name, Puzzle $survivor): string
    {
        $mergedId = Uuid::uuid7()->toString();

        $this->entityManager->persist(new PuzzleMergeAudit(
            id: Uuid::uuid7(),
            mergeRequestId: Uuid::uuid7(),
            survivorPuzzleId: $survivor->id,
            performedAt: new DateTimeImmutable('2026-09-20 10:00'),
            performedBy: null,
            decisionSource: MergeDecisionSource::AdminUi,
            snapshotBefore: [
                'survivorPuzzle' => ['id' => $survivor->id->toString()],
                'mergedPuzzles' => [[
                    'id' => $mergedId,
                    'name' => $name,
                    'ean' => '4005556123452',
                    'approved' => true,
                    'addedByUserId' => $this->id($addedBy),
                    'addedAt' => $addedAt->format(DATE_ATOM),
                ]],
            ],
            snapshotAfter: ['survivorPuzzle' => ['id' => $survivor->id->toString()]],
        ));
        $this->entityManager->flush();

        return $mergedId;
    }

    private function changeRequest(
        Puzzle $puzzle,
        Player $reporter,
        string $status,
        null|Player $reviewer = null,
        null|DateTimeImmutable $reviewedAt = null,
    ): PuzzleChangeRequest {
        $submittedAt = new DateTimeImmutable('2026-04-01 10:00');
        $request = new PuzzleChangeRequest(
            id: Uuid::uuid7(),
            puzzle: $puzzle,
            reporter: $reporter,
            submittedAt: $submittedAt,
            proposedName: 'Proposed',
            originalName: $puzzle->name,
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->database->executeStatement(
            'UPDATE puzzle_change_request SET status = :status, reviewed_by_id = :reviewer, reviewed_at = :at WHERE id = :id',
            [
                'status' => $status,
                'reviewer' => $reviewer?->id->toString(),
                'at' => $status === 'pending' ? null : ($reviewedAt ?? $submittedAt)->format('Y-m-d H:i:s'),
                'id' => $request->id->toString(),
            ],
        );

        return $request;
    }

    private function mergeRequest(Puzzle $puzzle, Player $reporter, string $status, null|Player $reviewer = null): PuzzleMergeRequest
    {
        $request = new PuzzleMergeRequest(
            id: Uuid::uuid7(),
            sourcePuzzle: $puzzle,
            reporter: $reporter,
            submittedAt: new DateTimeImmutable('2026-04-01 10:00'),
            reportedDuplicatePuzzleIds: [$puzzle->id->toString()],
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->database->executeStatement(
            'UPDATE puzzle_merge_request SET status = :status, reviewed_by_id = :reviewer, reviewed_at = :at, survivor_puzzle_id = :survivor WHERE id = :id',
            [
                'status' => $status,
                'reviewer' => $reviewer?->id->toString(),
                'at' => '2026-04-02 10:00:00',
                'survivor' => $status === 'approved' ? $puzzle->id->toString() : null,
                'id' => $request->id->toString(),
            ],
        );

        return $request;
    }

    private function decision(
        Player $decidedBy,
        PuzzleModerationAction $action,
        null|PuzzleChangeRequest $changeRequest = null,
        null|PuzzleMergeRequest $mergeRequest = null,
        null|Puzzle $puzzle = null,
        MergeDecisionSource $source = MergeDecisionSource::AdminUi,
    ): void {
        $this->entityManager->persist(new PuzzleModerationDecision(
            id: Uuid::uuid7(),
            action: $action,
            decidedAt: new DateTimeImmutable('2026-09-01 10:00'),
            decidedById: $decidedBy->id,
            decidedByName: $decidedBy->name,
            decidedByCode: $decidedBy->code,
            source: $source,
            puzzleId: $puzzle !== null ? $puzzle->id : $changeRequest?->puzzle->id,
            puzzleName: $puzzle?->name,
            changeRequestId: $changeRequest?->id,
            mergeRequestId: $mergeRequest?->id,
        ));
        $this->entityManager->flush();
    }
}
