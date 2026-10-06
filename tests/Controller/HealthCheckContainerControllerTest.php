<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthCheckContainerControllerTest extends WebTestCase
{
    public function testAnswersWithoutSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/health-check/container');

        $this->assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }
}
