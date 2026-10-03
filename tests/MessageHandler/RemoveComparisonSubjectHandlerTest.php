<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotRemoveYourselfFromComparison;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotFound;
use SpeedPuzzling\Web\Message\RemoveComparisonSubject;
use SpeedPuzzling\Web\Query\GetComparisonLineUp;
use SpeedPuzzling\Web\Results\ComparisonLineUpItem;
use SpeedPuzzling\Web\Tests\DataFixtures\ComparisonSubjectFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class RemoveComparisonSubjectHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetComparisonLineUp $getComparisonLineUp;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->getComparisonLineUp = self::getContainer()->get(GetComparisonLineUp::class);
    }

    public function testTheOwnerRemovesTheirRow(): void
    {
        $this->messageBus->dispatch(new RemoveComparisonSubject(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectFixture::STRIPE_PAIR));
        $this->messageBus->dispatch(new RemoveComparisonSubject(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectFixture::REGULAR_STRIPE));

        self::assertSame(
            [ComparisonSubjectFixture::STRIPE_SELF, ComparisonSubjectFixture::STRIPE_ADMIN, ComparisonSubjectFixture::STRIPE_REGULAR],
            $this->rowIds(PlayerFixture::PLAYER_WITH_STRIPE),
        );
        self::assertSame([ComparisonSubjectFixture::REGULAR_SELF], $this->rowIds(PlayerFixture::PLAYER_REGULAR));
    }

    public function testSomebodyElsesRowIsNotFound(): void
    {
        foreach ([ComparisonSubjectFixture::STRIPE_ADMIN, Uuid::uuid7()->toString(), 'not-an-id'] as $rowId) {
            try {
                $this->messageBus->dispatch(new RemoveComparisonSubject(PlayerFixture::PLAYER_REGULAR, $rowId));
                self::fail('Removed a row that is not the owner\'s: ' . $rowId);
            } catch (ComparisonSubjectNotFound) {
            }
        }

        self::assertCount(4, $this->rowIds(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testAFreePlayerStaysInTheirSoloLineUp(): void
    {
        try {
            $this->messageBus->dispatch(new RemoveComparisonSubject(PlayerFixture::PLAYER_REGULAR, ComparisonSubjectFixture::REGULAR_SELF));
            self::fail('A free player cannot remove themselves');
        } catch (CanNotRemoveYourselfFromComparison) {
        }

        self::assertSame(
            [ComparisonSubjectFixture::REGULAR_SELF, ComparisonSubjectFixture::REGULAR_STRIPE],
            $this->rowIds(PlayerFixture::PLAYER_REGULAR),
        );
    }

    public function testAMemberMayRemoveThemselves(): void
    {
        $this->messageBus->dispatch(new RemoveComparisonSubject(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonSubjectFixture::STRIPE_SELF));

        self::assertSame(
            [ComparisonSubjectFixture::STRIPE_ADMIN, ComparisonSubjectFixture::STRIPE_REGULAR, ComparisonSubjectFixture::STRIPE_PAIR],
            $this->rowIds(PlayerFixture::PLAYER_WITH_STRIPE),
        );
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
