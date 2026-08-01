<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An anonymous visitor sent to the login page must cost nothing on the server.
 *
 * This is the point of carrying the destination in `?return=`: a protected URL
 * is reachable by anyone, crawlers included, and there are 332
 * IsGranted/denyAccessUnlessGranted sites in this codebase. Every anonymous hit
 * used to write a session row (and a cookie) purely to remember a destination
 * nobody would come back for - production's sessions table reached 3,434,070
 * rows / 1.8 GB, a sample of which was 68,450/68,524 anonymous.
 */
final class LoginEntryPointTest extends WebTestCase
{
    public function testProtectedPageRedirectsToLoginCarryingTheDestination(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/admin/affiliates');

        self::assertResponseRedirects('/login?return=/admin/affiliates');
    }

    public function testQueryStringSurvivesInTheDestination(): void
    {
        $browser = self::createClient();

        // The OAuth2 authorize URL is the case that matters: its query string
        // *is* the request, so losing it would break third-party app sign-in
        $browser->request('GET', '/oauth2/authorize?client_id=abc&scope=profile%3Aread');

        $location = (string) $browser->getResponse()->headers->get('Location');

        self::assertStringStartsWith('/login?return=/oauth2/authorize', $location);
        self::assertStringContainsString('client_id', $location);
        self::assertStringContainsString('scope', $location);
    }

    public function testAnonymousRedirectStartsNoSessionAndSetsNoCookie(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/admin/affiliates');

        self::assertResponseRedirects();
        self::assertSame(
            [],
            $browser->getResponse()->headers->getCookies(),
            'Sending an anonymous visitor to login must not cost a session row or a cookie',
        );
        self::assertFalse(
            $browser->getRequest()->getSession()->isStarted(),
            'No session may be started for an anonymous hit on a protected page',
        );
    }

    public function testLoginPageItselfIsNotOfferedAsADestination(): void
    {
        $browser = self::createClient();

        // Would otherwise loop the visitor back to the page they are already on
        $browser->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('name="return"', (string) $browser->getResponse()->getContent());
    }
}
