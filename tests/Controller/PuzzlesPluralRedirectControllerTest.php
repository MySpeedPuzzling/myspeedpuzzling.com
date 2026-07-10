<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzlesPluralRedirectControllerTest extends WebTestCase
{
    public function testEnglishPuzzlesPluralRedirectsPermanentlyToPuzzles(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzles');

        $this->assertResponseRedirects('/en/puzzle', 301);
    }
}
