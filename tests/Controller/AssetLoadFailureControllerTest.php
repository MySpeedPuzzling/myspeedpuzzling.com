<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AssetLoadFailureControllerTest extends WebTestCase
{
    public function testValidBeaconIsAcceptedWithoutSession(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/asset-load-failure', content: (string) json_encode([
            'url' => 'https://myspeedpuzzling.com/build/app.abc123.js',
            'page' => '/en/puzzles',
            'controlled' => true,
            'retry' => false,
        ]));

        $this->assertResponseStatusCodeSame(204);
        self::assertNull($browser->getResponse()->headers->get('Set-Cookie'));
    }

    public function testGarbageBodyIsAcceptedSilently(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/-/asset-load-failure', content: 'not-json{{{');

        $this->assertResponseStatusCodeSame(204);
    }

    public function testGetIsNotAllowed(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/-/asset-load-failure');

        $this->assertResponseStatusCodeSame(405);
    }
}
