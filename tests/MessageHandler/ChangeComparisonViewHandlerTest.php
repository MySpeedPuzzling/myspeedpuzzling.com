<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangeComparisonView;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ComparisonView;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChangeComparisonViewHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetPlayerProfile $getPlayerProfile;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->getPlayerProfile = self::getContainer()->get(GetPlayerProfile::class);
    }

    public function testDefaultIsCardsAndTheChoiceIsStored(): void
    {
        self::assertSame(ComparisonView::Cards, $this->getPlayerProfile->byUserId(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)->comparisonView);

        foreach ([ComparisonView::Table, ComparisonView::Duel, ComparisonView::Cards] as $view) {
            $this->messageBus->dispatch(new ChangeComparisonView(PlayerFixture::PLAYER_WITH_STRIPE, $view));

            // The signed-in player's profile row carries it - no query of its own
            self::assertSame($view, $this->getPlayerProfile->byUserId(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)->comparisonView);
        }
    }

    public function testOnlyTheViewersOwnProfileCarriesTheChoice(): void
    {
        $this->messageBus->dispatch(new ChangeComparisonView(PlayerFixture::PLAYER_WITH_STRIPE, ComparisonView::Table));

        self::assertSame(ComparisonView::Cards, $this->getPlayerProfile->byId(PlayerFixture::PLAYER_WITH_STRIPE)->comparisonView);
    }

    public function testAnUnknownStoredValueReadsAsCards(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE player SET comparison_view = 'pie' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        self::assertSame(ComparisonView::Cards, $this->getPlayerProfile->byUserId(PlayerFixture::PLAYER_WITH_STRIPE_USER_ID)->comparisonView);
    }

    public function testUnknownPlayer(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->messageBus->dispatch(new ChangeComparisonView('00000000-0000-0000-0000-000000000099', ComparisonView::Duel));
    }
}
