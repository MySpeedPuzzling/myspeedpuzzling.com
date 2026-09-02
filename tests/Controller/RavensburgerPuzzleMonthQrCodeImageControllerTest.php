<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RavensburgerPuzzleMonthQrCodeImageControllerTest extends WebTestCase
{
    public function testQrCodeImageIsServedAsPng(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/ravensburger-puzzle-month/1/qr-code.png');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'image/png');
        self::assertStringStartsWith("\x89PNG", (string) $browser->getResponse()->getContent());
    }

    public function testUnknownEditionIsNotFound(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/ravensburger-puzzle-month/999/qr-code.png');

        $this->assertResponseStatusCodeSame(404);
    }
}
