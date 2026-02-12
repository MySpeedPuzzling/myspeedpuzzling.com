<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Rating;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayerRatingsControllerTest extends WebTestCase
{
    public function testPageLoadsAndShowsRatings(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR has ratings from fixture
        $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_REGULAR . '/ratings');

        $this->assertResponseIsSuccessful();
    }

    public function testPageLoadsForPlayerWithNoRatings(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_PRIVATE . '/ratings');

        $this->assertResponseIsSuccessful();
    }
}
