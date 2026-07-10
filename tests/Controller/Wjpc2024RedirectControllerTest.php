<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class Wjpc2024RedirectControllerTest extends WebTestCase
{
    public function testEnglishWjpcPageRedirectsPermanentlyToEvents(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/wjpc-2024');

        $this->assertResponseRedirects('/en/events', 301);
    }

    public function testCzechWjpcPageRedirectsPermanentlyToEvents(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/wjpc-2024');

        $this->assertResponseRedirects('/eventy', 301);
    }
}
