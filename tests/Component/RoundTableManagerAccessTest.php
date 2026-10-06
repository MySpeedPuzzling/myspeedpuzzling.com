<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\TableLayoutFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The table layout page checks the permission once; every Live action is a request of its own
 * and must check it again, and may only touch rows, tables and spots of its own round.
 */
final class RoundTableManagerAccessTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    public function testMaintainerChangesASpotOfTheRound(): void
    {
        $component = $this->component(PlayerFixture::PLAYER_ADMIN, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
        $component->call('clearSpot', ['spotId' => TableLayoutFixture::SPOT_MANUAL_NAME]);

        self::assertNull($this->spotPlayerName(TableLayoutFixture::SPOT_MANUAL_NAME));
    }

    public function testPlayerWhoDoesNotManageTheEventIsDenied(): void
    {
        try {
            // PLAYER_REGULAR maintains other events, not WJPC 2024
            $component = $this->component(PlayerFixture::PLAYER_REGULAR, CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION);
            $component->call('clearSpot', ['spotId' => TableLayoutFixture::SPOT_MANUAL_NAME]);
            self::fail('A player who does not manage the event must not change its table layout');
        } catch (AccessDeniedHttpException) {
        }

        self::assertNotNull($this->spotPlayerName(TableLayoutFixture::SPOT_MANUAL_NAME));
    }

    public function testSpotOfAnotherRoundIsNotFound(): void
    {
        // The fixture layout belongs to the qualification round, the component manages the final
        $component = $this->component(PlayerFixture::PLAYER_ADMIN, CompetitionRoundFixture::ROUND_WJPC_FINAL);

        try {
            $component->call('deleteSpot', ['spotId' => TableLayoutFixture::SPOT_EMPTY]);
            self::fail('A spot of another round must not be deleted');
        } catch (NotFoundHttpException) {
        }

        self::assertSame(1, $this->spotCount(TableLayoutFixture::SPOT_EMPTY));
    }

    public function testTableOfAnotherRoundIsNotFound(): void
    {
        $component = $this->component(PlayerFixture::PLAYER_ADMIN, CompetitionRoundFixture::ROUND_WJPC_FINAL);

        $this->expectException(NotFoundHttpException::class);
        $component->call('deleteTable', ['tableId' => TableLayoutFixture::ROUND_TABLE_1]);
    }

    private function component(string $playerId, string $roundId): TestLiveComponent
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, $playerId);

        $component = $this->createLiveComponent('RoundTableManager', [
            'roundId' => $roundId,
            'competitionId' => CompetitionFixture::COMPETITION_WJPC_2024,
        ], $client);
        $component->setRouteLocale('en');

        return $component;
    }

    private function spotPlayerName(string $spotId): null|string
    {
        /** @var false|null|string $name */
        $name = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT player_name FROM table_spot WHERE id = :id',
            ['id' => $spotId],
        );

        return $name === false ? null : $name;
    }

    private function spotCount(string $spotId): int
    {
        /** @var int $count */
        $count = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT count(*) FROM table_spot WHERE id = :id',
            ['id' => $spotId],
        );

        return $count;
    }
}
