<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetPlayerProfileOptOutTest extends KernelTestCase
{
    private GetPlayerProfile $query;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var GetPlayerProfile $query */
        $query = $container->get(GetPlayerProfile::class);
        $this->query = $query;

        /** @var PlayerRepository $playerRepository */
        $playerRepository = $container->get(PlayerRepository::class);
        $this->playerRepository = $playerRepository;
    }

    public function testDefaultOptOutFlagsAreFalse(): void
    {
        $profile = $this->query->byId(PlayerFixture::PLAYER_REGULAR);

        self::assertFalse($profile->streakOptedOut);
        self::assertFalse($profile->rankingOptedOut);
    }

    public function testOptOutFlagsReflectEntityState(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->changeStreakOptedOut(true);
        $player->changeRankingOptedOut(true);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $profile = $this->query->byId(PlayerFixture::PLAYER_REGULAR);

        self::assertTrue($profile->streakOptedOut);
        self::assertTrue($profile->rankingOptedOut);
    }

    public function testOptOutFlagsViaByUserId(): void
    {
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->changeStreakOptedOut(true);
        $player->changeRankingOptedOut(true);
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $profile = $this->query->byUserId(PlayerFixture::PLAYER_REGULAR_USER_ID);

        self::assertTrue($profile->streakOptedOut);
        self::assertTrue($profile->rankingOptedOut);
    }
}
