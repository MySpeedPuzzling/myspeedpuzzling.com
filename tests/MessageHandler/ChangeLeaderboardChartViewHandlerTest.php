<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangeLeaderboardChartView;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\LeaderboardChartView;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChangeLeaderboardChartViewHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    private GetPlayerProfile $getPlayerProfile;

    private string $userId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getPlayerProfile = $container->get(GetPlayerProfile::class);

        $userId = $container->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE)->userId;
        assert($userId !== null);
        $this->userId = $userId;
    }

    public function testDefaultIsTheDistributionAndTheChoiceIsStored(): void
    {
        self::assertSame(LeaderboardChartView::Distribution, $this->getPlayerProfile->byUserId($this->userId)->leaderboardChartView);

        foreach ([LeaderboardChartView::Ranking, LeaderboardChartView::Distribution] as $view) {
            $this->messageBus->dispatch(new ChangeLeaderboardChartView(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                view: $view,
            ));

            // The signed-in player's profile row carries it - no query of its own
            self::assertSame($view, $this->getPlayerProfile->byUserId($this->userId)->leaderboardChartView);
        }
    }

    public function testOnlyTheViewersOwnProfileCarriesTheChoice(): void
    {
        $this->messageBus->dispatch(new ChangeLeaderboardChartView(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            view: LeaderboardChartView::Ranking,
        ));

        // Somebody else's profile (byId) never reads the column
        self::assertSame(LeaderboardChartView::Distribution, $this->getPlayerProfile->byId(PlayerFixture::PLAYER_WITH_STRIPE)->leaderboardChartView);
    }

    public function testAnUnknownStoredValueReadsAsTheDistribution(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE player SET leaderboard_chart_view = 'pie' WHERE id = :id",
            ['id' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        self::assertSame(LeaderboardChartView::Distribution, $this->getPlayerProfile->byUserId($this->userId)->leaderboardChartView);
    }

    public function testUnknownPlayer(): void
    {
        // PlayerNotFound is an HTTP exception, so UnwrapHttpExceptionMiddleware hands it over bare
        $this->expectException(PlayerNotFound::class);

        $this->messageBus->dispatch(new ChangeLeaderboardChartView(
            playerId: '00000000-0000-0000-0000-000000000099',
            view: LeaderboardChartView::Ranking,
        ));
    }
}
