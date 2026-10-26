<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetBorrowCandidates;
use SpeedPuzzling\Web\Results\BorrowCandidate;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Tests\TestClock;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who may be asked to lend a puzzle (docs/features/wishlist-availability.md): a favorite whose collection holding it is
 * public, visible to the asker, with no block between them, never a secret puzzle.
 */
final class GetBorrowCandidatesTest extends KernelTestCase
{
    use BorrowSeeding;

    private string $asker;
    private string $owner;
    private string $puzzle;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->asker = $this->seedPlayer('Asker');
        $this->owner = $this->seedPlayer('Owner');
        $this->puzzle = $this->seedPuzzle(500, 'Lighthouse');
        $this->seedFavorite($this->asker, $this->owner);
        $this->seedCollectionVisibility($this->owner, true);
        $this->seedWished($this->asker, $this->puzzle);
    }

    public function testAFavoriteWithThePuzzleInTheirPublicCollectionIsListed(): void
    {
        $this->seedOwned($this->owner, $this->puzzle);

        $candidates = $this->candidates();

        self::assertCount(1, $candidates);
        self::assertSame($this->owner, $candidates[0]->ownerId);
        self::assertSame($this->puzzle, $candidates[0]->puzzleId);
        self::assertSame('Owner', $candidates[0]->ownerName);
        self::assertFalse($candidates[0]->lentOut);
    }

    public function testAPrivateSystemCollectionHidesThePuzzle(): void
    {
        $this->seedCollectionVisibility($this->owner, false);
        $this->seedOwned($this->owner, $this->puzzle);

        self::assertSame([], $this->candidates());
    }

    public function testCustomCollectionsCountOnlyWhenPublic(): void
    {
        $this->seedCollectionVisibility($this->owner, false);
        $this->seedOwned($this->owner, $this->puzzle, $this->seedCustomCollection($this->owner, false));

        self::assertSame([], $this->candidates());

        $this->seedOwned($this->owner, $this->puzzle, $this->seedCustomCollection($this->owner, true));

        self::assertCount(1, $this->candidates(), 'One owner even when the puzzle is in several collections');
    }

    public function testSomebodyWhoIsNotAFavoriteIsNotListed(): void
    {
        $stranger = $this->seedPlayer('Stranger');
        $this->seedCollectionVisibility($stranger, true);
        $this->seedOwned($stranger, $this->puzzle);

        self::assertSame([], $this->candidates());
    }

    public function testABlockEitherWayHidesTheOwner(): void
    {
        $this->seedOwned($this->owner, $this->puzzle);
        $this->seedBlock($this->owner, $this->asker);

        self::assertSame([], $this->candidates(), 'The owner blocks the asker');

        $other = $this->seedPlayer('Other owner');
        $this->seedFavorite($this->asker, $other);
        $this->seedCollectionVisibility($other, true);
        $this->seedOwned($other, $this->puzzle);
        $this->seedBlock($this->asker, $other);

        self::assertSame([], $this->candidates(), 'The asker blocks the owner');
    }

    public function testAPrivateOwnerOnlyWithTheAskerOnTheirAllowList(): void
    {
        $this->borrowDatabase()->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => $this->owner]);
        $this->seedOwned($this->owner, $this->puzzle);

        self::assertSame([], $this->candidates());

        $this->seedAllowedViewer($this->owner, $this->asker);

        self::assertCount(1, $this->candidates());
    }

    public function testALentOutPuzzleIsListedAsLentOut(): void
    {
        $this->seedOwned($this->owner, $this->puzzle);
        $this->seedLentOut($this->owner, $this->puzzle);

        $candidates = $this->candidates();

        self::assertCount(1, $candidates);
        self::assertTrue($candidates[0]->lentOut);
    }

    public function testASecretPuzzleIsNeverListed(): void
    {
        $secret = $this->seedPuzzle(500, 'Secret', hideUntil: TestClock::now()->modify('+10 days'));
        $this->seedOwned($this->owner, $secret);
        $this->seedWished($this->asker, $secret);

        self::assertSame([], self::getContainer()->get(GetBorrowCandidates::class)->forPuzzles($this->asker, [$secret]));
    }

    public function testOnlyPuzzlesOnTheAskersWishlist(): void
    {
        $notWished = $this->seedPuzzle(500, 'Not wished');
        $this->seedOwned($this->owner, $notWished);

        self::assertSame([], self::getContainer()->get(GetBorrowCandidates::class)->forPuzzles($this->asker, [$notWished]));
    }

    /**
     * @return list<BorrowCandidate>
     */
    private function candidates(): array
    {
        return self::getContainer()->get(GetBorrowCandidates::class)->forPuzzles($this->asker, [$this->puzzle]);
    }
}
