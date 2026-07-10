<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzleDetailQrRedirectControllerTest extends WebTestCase
{
    public function testQrUrlRedirectsPermanentlyToPuzzleDetail(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/solving-puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseRedirects('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 301);
    }
}
