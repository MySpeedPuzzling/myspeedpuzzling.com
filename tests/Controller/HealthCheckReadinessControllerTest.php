<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Controller\HealthCheckReadinessController;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthCheckReadinessControllerTest extends WebTestCase
{
    public function testReadyWithoutDrainMarker(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/health-check/readiness');

        $this->assertResponseIsSuccessful();
        self::assertSame('{"status":"ready"}', $browser->getResponse()->getContent());
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
    }

    public function testDrainingWhileTheMarkerExists(): void
    {
        $marker = sys_get_temp_dir() . '/drain-test-' . bin2hex(random_bytes(6));
        $controller = new HealthCheckReadinessController($marker);

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
