<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ClearComparisonLineUp;
use SpeedPuzzling\Web\Query\GetComparisonLineUp;
use SpeedPuzzling\Web\Results\ComparisonLineUpItem;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ComparisonKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * "Clear" of the compare page (docs/features/player-comparison.md): one line-up of one owner, the owner's own Solo row
 * stays - for members too.
 */
final class ClearComparisonLineUpHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetComparisonLineUp $getComparisonLineUp;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->getComparisonLineUp = self::getContainer()->get(GetComparisonLineUp::class);
    }

    public function testClearingSoloKeepsYouAndEveryOtherKind(): void
    {
        // A member: Solo = herself + PLAYER_ADMIN + PLAYER_REGULAR, Pairs = one pair
        $this->messageBus->dispatch(new ClearComparisonLineUp(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonKind::Solo));

        self::assertSame(
            [ComparisonSubjectFixture::STRIPE_SELF, ComparisonSubjectFixture::STRIPE_PAIR],
            $this->rowIds(PlayerFixture::PLAYER_WITH_STRIPE),
        );
        // Somebody else's line-up - even with her in it - is none of this
        self::assertSame(
            [ComparisonSubjectFixture::REGULAR_SELF, ComparisonSubjectFixture::REGULAR_STRIPE],
            $this->rowIds(PlayerFixture::PLAYER_REGULAR),
        );
    }

    public function testClearingPairsTakesEveryPairAndLeavesSolo(): void
    {
        $this->messageBus->dispatch(new ClearComparisonLineUp(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonKind::Pairs));

        self::assertSame(
            [ComparisonSubjectFixture::STRIPE_SELF, ComparisonSubjectFixture::STRIPE_ADMIN, ComparisonSubjectFixture::STRIPE_REGULAR],
            $this->rowIds(PlayerFixture::PLAYER_WITH_STRIPE),
        );
    }

    public function testAFreePlayerClearsTheirSoloLineUp(): void
    {
        $this->messageBus->dispatch(new ClearComparisonLineUp(PlayerFixture::PLAYER_REGULAR, ComparisonKind::Solo));

        self::assertSame([ComparisonSubjectFixture::REGULAR_SELF], $this->rowIds(PlayerFixture::PLAYER_REGULAR));
    }

    public function testAnEmptyLineUpIsNothingToClear(): void
    {
        $this->messageBus->dispatch(new ClearComparisonLineUp(PlayerFixture::PLAYER_REGULAR, ComparisonKind::Teams));
        $this->messageBus->dispatch(new ClearComparisonLineUp(PlayerFixture::PLAYER_ADMIN, ComparisonKind::Solo));

        self::assertCount(2, $this->rowIds(PlayerFixture::PLAYER_REGULAR));
        self::assertSame([], $this->rowIds(PlayerFixture::PLAYER_ADMIN));
    }

    public function testUnknownPlayer(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->messageBus->dispatch(new ClearComparisonLineUp('00000000-0000-0000-0000-000000000099', ComparisonKind::Solo));
    }

    /**
     * @return list<string>
     */
    private function rowIds(string $playerId): array
    {
        return array_map(
            static fn (ComparisonLineUpItem $item): string => $item->rowId,
            $this->getComparisonLineUp->forPlayer($playerId),
        );
    }
}
