<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AskToBorrowPuzzles;
use SpeedPuzzling\Web\Query\GetBorrowRequests;
use SpeedPuzzling\Web\Tests\BorrowSeeding;
use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Borrow requests as their two sides see them (docs/features/wishlist-availability.md): nobody else sees one, who asks
 * is named to the owner even when private, an owner the asker can no longer see is shown by code.
 */
final class GetBorrowRequestsTest extends KernelTestCase
{
    use BorrowSeeding;

    public function testBothSidesSeeTheRequestAndNobodyElse(): void
    {
        self::bootKernel();

        $asker = $this->seedPlayer('Jana', private: true);
        $owner = $this->seedPlayer('Anna', code: 'annacode');
        $puzzle = $this->seedPuzzle(1000, 'Lighthouse');
        $this->seedFavorite($asker, $owner);
        $this->seedCollectionVisibility($owner, true);
        $this->seedOwned($owner, $puzzle);
        $this->seedWished($asker, $puzzle);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AskToBorrowPuzzles(Uuid::uuid7()->toString(), $asker, [$owner => [$puzzle]], [$owner => 'Hi']));

        $query = self::getContainer()->get(GetBorrowRequests::class);

        $received = $query->askedOf($owner);
        self::assertCount(1, $received);
        self::assertSame('Jana', $received[0]->counterpartName, 'Who asks is named even when private');
        self::assertTrue($received[0]->counterpartIsPrivate);
        self::assertSame('Hi', $received[0]->note);
        self::assertSame(BorrowRequestItemStatus::Waiting, $received[0]->items[0]->status);
        self::assertTrue($received[0]->isWaiting());

        $asked = $query->askedBy($asker);
        self::assertCount(1, $asked);
        self::assertSame('Anna', $asked[0]->counterpartDisplayName());

        $requestId = $asked[0]->requestId;
        self::assertNotNull($query->forViewer($requestId, $asker));
        self::assertNotNull($query->forViewer($requestId, $owner));
        self::assertNull($query->forViewer($requestId, $this->seedPlayer('Stranger')));

        self::assertSame([$puzzle], array_keys($query->openOfAsker($asker, [$puzzle])));

        // The owner turns private: the asker sees the code only
        $this->borrowDatabase()->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => $owner]);
        self::assertSame('#ANNACODE', $query->askedBy($asker)[0]->counterpartDisplayName());
    }
}
