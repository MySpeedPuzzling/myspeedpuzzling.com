<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Panther;

final class SmokeTest extends AbstractPantherTestCase
{
    public function testHomepageLoadsSuccessfully(): void
    {
        $client = self::createBrowserClient();

        $client->request('GET', '/en/home');

        self::assertSelectorExists('body');
        self::assertPageTitleContains('Speed Puzzling');
    }

    public function testLadderPageLoads(): void
    {
        $client = self::createBrowserClient();

        $client->request('GET', '/en/ladder');

        self::assertSelectorExists('.table');
    }
}
