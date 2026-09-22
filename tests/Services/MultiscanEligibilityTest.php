<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Results\UserPuzzleStatuses;
use SpeedPuzzling\Web\Services\MultiscanEligibility;
use SpeedPuzzling\Web\Value\MultiscanAction;

final class MultiscanEligibilityTest extends TestCase
{
    private const string OWNED = 'p-owned';
    private const string LENT = 'p-lent';
    private const string BORROWED = 'p-borrowed';
    private const string WISHED = 'p-wished';
    private const string RETURNED_ROW = 'p-returned-row';
    private const string FRESH = 'p-fresh';

    private function statuses(): UserPuzzleStatuses
    {
        return new UserPuzzleStatuses(
            solved: [],
            wishlist: [self::WISHED],
            unsolved: [],
            collection: [self::OWNED, self::LENT],
            borrowed: [self::BORROWED],
            lent: [self::LENT, self::RETURNED_ROW],
            sellSwap: [],
            lentPuzzleIds: [self::LENT => 'lent-1', self::RETURNED_ROW => 'lent-2'],
            borrowedPuzzleIds: [self::BORROWED => 'lent-3'],
            puzzleCollections: [
                self::OWNED => [Collection::SYSTEM_ID => '__system_collection__', 'col-1' => 'Favourites'],
                self::LENT => [Collection::SYSTEM_ID => '__system_collection__'],
            ],
            lentToNames: [self::LENT => 'Anna'],
            borrowedFromNames: [self::BORROWED => 'Petr'],
        );
    }

    public function testAddToLibrarySkipsWhatIsAlreadyInThatCollection(): void
    {
        $report = (new MultiscanEligibility())->check(MultiscanAction::AddToLibrary, [self::OWNED, self::FRESH, self::OWNED], $this->statuses());

        self::assertSame([self::FRESH], $report->eligible);
        self::assertSame(['already_in_collection'], array_values($report->skipped));

        $named = (new MultiscanEligibility())->check(MultiscanAction::AddToLibrary, [self::OWNED, self::LENT], $this->statuses(), 'col-1');
        self::assertSame([self::LENT], $named->eligible, 'LENT is in the system collection only, so it can go into Favourites');
    }

    public function testWishlistSkipsOwnedAndAlreadyWished(): void
    {
        $report = (new MultiscanEligibility())->check(MultiscanAction::AddToWishlist, [self::OWNED, self::WISHED, self::FRESH, self::BORROWED], $this->statuses());

        self::assertSame([self::FRESH, self::BORROWED], $report->eligible);
        self::assertSame('already_in_library', $report->reasonFor(self::OWNED));
        self::assertSame('already_on_wishlist', $report->reasonFor(self::WISHED));
    }

    public function testLendSkipsOpenLendsButNotReturnedRows(): void
    {
        $report = (new MultiscanEligibility())->check(MultiscanAction::Lend, [self::LENT, self::RETURNED_ROW, self::FRESH], $this->statuses());

        self::assertSame([self::RETURNED_ROW, self::FRESH], $report->eligible);
        self::assertSame('already_lent', $report->reasonFor(self::LENT));
        self::assertSame('Anna', $report->counterpartyNames[self::LENT]);
    }

    public function testBorrowSkipsWhatIsAlreadyBorrowed(): void
    {
        $report = (new MultiscanEligibility())->check(MultiscanAction::Borrow, [self::BORROWED, self::FRESH], $this->statuses());

        self::assertSame([self::FRESH], $report->eligible);
        self::assertSame('already_borrowed', $report->reasonFor(self::BORROWED));
        self::assertSame('Petr', $report->counterpartyNames[self::BORROWED]);
    }

    public function testReturnCoversOwnedAndHeldLendsAndMapsToLentPuzzleIds(): void
    {
        $report = (new MultiscanEligibility())->check(MultiscanAction::Return, [self::LENT, self::BORROWED, self::FRESH, self::RETURNED_ROW], $this->statuses());

        self::assertSame([self::LENT, self::BORROWED], $report->eligible);
        self::assertSame(['lent-1', 'lent-3'], array_values($report->lentPuzzleIds));
        self::assertSame('not_lent', $report->reasonFor(self::FRESH));
        self::assertSame('not_lent', $report->reasonFor(self::RETURNED_ROW), 'a lent_puzzle row without a holder is not an open lend');
    }
}
