<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomepageControllerTest extends WebTestCase
{
    public function testResponseIsSuccessful(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/home');

        $this->assertResponseIsSuccessful();
    }
}
