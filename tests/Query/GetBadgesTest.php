<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetBadgesTest extends KernelTestCase
{
    private GetBadges $query;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetBadges::class);
    }

    public function testReturnsEmptyArrayForPlayerWithNoBadges(): void
    {
        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        // No badge fixtures exist — empty is correct
        self::assertSame([], $badges);
    }

    public function testReturnsEmptyArrayForNonExistentPlayer(): void
    {
        $badges = $this->query->forPlayer('00000000-0000-0000-0000-000000000099');

        self::assertSame([], $badges);
    }

    public function testQueryDoesNotErrorOnPlayerWithoutBadges(): void
    {
        $badges = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(0, $badges);
    }
}
