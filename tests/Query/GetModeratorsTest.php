<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Query\GetModerators;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class GetModeratorsTest extends KernelTestCase
{
    public function testAdminsAreNotListedAsModerators(): void
    {
        self::bootKernel();

        self::assertCount(0, self::getContainer()->get(GetModerators::class)->all());
    }

    public function testListsPlayersHoldingTheRole(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $container->get(MessageBusInterface::class)->dispatch(new GrantModeratorRole(PlayerFixture::PLAYER_REGULAR));

        $moderators = $container->get(GetModerators::class)->all();

        self::assertCount(1, $moderators);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $moderators[0]->playerId);
        self::assertSame(0, $moderators[0]->reviewedChangeRequests);
        self::assertSame(0, $moderators[0]->reviewedMergeRequests);
        self::assertNull($moderators[0]->lastReviewAt);
    }
}
