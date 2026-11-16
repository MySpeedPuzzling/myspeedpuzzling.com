<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Stripe only ever POSTs its events; any other method is refused by the router before the handler runs.
 */
final class StripeWebhookControllerTest extends WebTestCase
{
    public function testGetIsMethodNotAllowed(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/webhook/stripe');

        self::assertResponseStatusCodeSame(405);
    }

    public function testPostWithoutSignatureIsRefused(): void
    {
        $browser = self::createClient();

        $browser->request('POST', '/webhook/stripe', content: '{}');

        self::assertResponseStatusCodeSame(400);
    }
}
