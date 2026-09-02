<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Value\RavensburgerPuzzleMonthEditions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RavensburgerPuzzleMonthRedirectControllerTest extends WebTestCase
{
    public function testEditionRedirectsToPuzzleDetailInPreferredLanguage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/ravensburger-puzzle-month/1', server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);

        $this->assertResponseRedirects(
            '/de/puzzle/' . RavensburgerPuzzleMonthEditions::PUZZLE_IDS[1] . '?utm_source=puzzle_box&utm_campaign=ravensburger-puzzle-month-1',
            302,
        );
    }

    public function testEditionFallsBackToEnglishForUnknownLanguage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/ravensburger-puzzle-month/1', server: ['HTTP_ACCEPT_LANGUAGE' => 'xx']);

        $this->assertResponseRedirects(
            '/en/puzzle/' . RavensburgerPuzzleMonthEditions::PUZZLE_IDS[1] . '?utm_source=puzzle_box&utm_campaign=ravensburger-puzzle-month-1',
            302,
        );
    }

    public function testUnknownEditionIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/ravensburger-puzzle-month/999');

        $this->assertResponseStatusCodeSame(404);
    }
}
