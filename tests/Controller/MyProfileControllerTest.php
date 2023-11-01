<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MyProfileControllerTest extends WebTestCase
{
    public function testPageCanBeRenderedWithoutLogin(): void
    {
        $client = self::createClient();

        $client->request('GET', '/muj-profil');

        self::assertResponseIsSuccessful();
    }
}
