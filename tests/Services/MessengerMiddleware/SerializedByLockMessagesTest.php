<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MessengerMiddleware;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Message\AddPuzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzle;
use SpeedPuzzling\Web\Message\ApprovePuzzleChangeRequest;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Message\ClearComparisonLineUp;
use SpeedPuzzling\Web\Message\EditPuzzle;
use SpeedPuzzling\Web\Message\LinkEanToPuzzle;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SerializedByLockMessagesTest extends TestCase
{
    /**
     * TerminateMembershipDueToDisputeHandler still locks inside the handler (its subscription id
     * is only known after asking Stripe) - it stays mutually exclusive with these two only while
     * all three use the very same key.
     */
    public function testMembershipMessagesLockTheSubscriptionUnderTheSameKey(): void
    {
        self::assertSame('stripe-subscription-sub_123', (new UpdateMembershipSubscription('sub_123'))->lockKey());
        self::assertSame('stripe-subscription-sub_123', (new CancelMembershipSubscription('sub_123'))->lockKey());
    }

    /**
     * Every add of one owner waits for the previous one to commit, whatever it adds - the cap is counted per owner
     */
    public function testComparisonAddsLockTheOwnersLineUps(): void
    {
        $owner = '018d0000-0000-0000-0000-00000000000A';
        $key = (new AddComparisonSubject($owner, 'p-018d0000-0000-0000-0000-00000000000b'))->lockKey();

        self::assertSame('comparison-line-up-018d0000-0000-0000-0000-00000000000a', $key);
        self::assertSame($key, (new AddComparisonSubject(strtolower($owner), 't-018d0000-0000-0000-0000-00000000000c', '018d0000-0000-0000-0000-00000000000d'))->lockKey());
        self::assertNotSame($key, (new AddComparisonSubject('018d0000-0000-0000-0000-00000000000e', 'p-018d0000-0000-0000-0000-00000000000b'))->lockKey());

        // "Clear" waits for the adds of the same owner and they for it - an add never lands half way through a clear
        self::assertSame($key, (new ClearComparisonLineUp($owner, ComparisonKind::Pairs))->lockKey());
    }

    /**
     * Every message that changes a puzzle's record waits for the others on the same puzzle - the record version check
     * (PuzzleRecordVersion) runs inside the transaction, so two saves must not check at the same moment
     */
    public function testEveryChangeOfAPuzzleRecordLocksThePuzzle(): void
    {
        $puzzleId = '018D0003-0000-0000-0000-000000000001';
        $key = 'puzzle-018d0003-0000-0000-0000-000000000001';
        $values = new PuzzleRecordValues(name: 'Puzzle', nameLanguage: null, alternativeNames: new PuzzleNames(), manufacturerId: null, piecesCount: 500, ean: null, identificationNumber: null);

        self::assertSame($key, (new EditPuzzle($puzzleId, 'editor', $values))->lockKey());
        self::assertSame($key, (new ApprovePuzzle($puzzleId, 'reviewer', 'Puzzle', 500, null, null))->lockKey());
        self::assertSame($key, (new ApprovePuzzleChangeRequest('change-request', $puzzleId, 'reviewer'))->lockKey());
        self::assertSame($key, (new ApprovePuzzleMergeRequest('merge-request', 'reviewer', $puzzleId, 'Puzzle', null, null, 500, null, null))->lockKey());
        self::assertSame($key, (new LinkEanToPuzzle($puzzleId, 'player', '4005556147090'))->lockKey());
        self::assertSame($key, (new AddPuzzle(
            Uuid::fromString($puzzleId),
            'player',
            'Puzzle',
            'Brand',
            500,
            new UploadedFile(__FILE__, 'box.jpg', test: true),
            null,
            null,
        ))->lockKey());
    }
}
