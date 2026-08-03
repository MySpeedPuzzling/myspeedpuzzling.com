<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlayerPuzzleResultsControllerTest extends WebTestCase
{
    public function testSoloResultsPageLoads(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_REGULAR . '/puzzle/' . PuzzleFixture::PUZZLE_500_02 . '/results/solo');

        $this->assertResponseIsSuccessful();
    }

    public function testModalModeLoads(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_REGULAR . '/puzzle/' . PuzzleFixture::PUZZLE_500_02 . '/results/solo', [], [], [
            'HTTP_TURBO_FRAME' => 'modal-frame',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testReturns404WhenNoResults(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_REGULAR . '/puzzle/' . PuzzleFixture::PUZZLE_1500_02 . '/results/solo');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDuoResultsPageLoads(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_REGULAR . '/puzzle/' . PuzzleFixture::PUZZLE_1000_01 . '/results/duo');

        $this->assertResponseIsSuccessful();
    }
}
