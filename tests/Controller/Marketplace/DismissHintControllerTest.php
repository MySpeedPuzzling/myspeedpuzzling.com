<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Marketplace;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DismissHintControllerTest extends WebTestCase
{
    public function testDismissHintRequiresAuthentication(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'marketplace_disclaimer']);

        $this->assertResponseRedirects();
    }

    public function testDismissHintWithValidType(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'marketplace_disclaimer']);

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDismissHintWithInvalidType(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'invalid_type']);

        $this->assertResponseStatusCodeSame(400);
    }
}
