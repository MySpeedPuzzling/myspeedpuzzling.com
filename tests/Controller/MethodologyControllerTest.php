<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MethodologyControllerTest extends WebTestCase
{
    public function testPageIsAccessible(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/methodology');

        $this->assertResponseIsSuccessful();
    }
}
