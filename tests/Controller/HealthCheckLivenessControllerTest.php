<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Controller\HealthCheckLivenessController;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthCheckLivenessControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/health-check/liveness');

        $this->assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/-/health-check/liveness');

        $this->assertResponseIsSuccessful();
    }

    public function testDrainingWhileTheMarkerExists(): void
    {
        $marker = sys_get_temp_dir() . '/drain-test-' . bin2hex(random_bytes(6));
        $controller = new HealthCheckLivenessController($marker);

        self::assertSame(200, $controller()->getStatusCode());

        touch($marker);

        try {
            $response = $controller();
            self::assertSame(503, $response->getStatusCode());
            self::assertSame('{"status":"draining"}', $response->getContent());
        } finally {
            unlink($marker);
        }

        self::assertSame(200, $controller()->getStatusCode());
    }
}
