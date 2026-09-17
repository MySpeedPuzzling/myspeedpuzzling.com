<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleIntelligenceFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddedTimeRecapControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
    }

    public function testCollectionCtaIsHiddenWhenPuzzleIsAlreadyInCollection(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_01);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#recap-collection-cta');
    }

    public function testCollectionCtaIsHiddenWhenPuzzleIsBorrowed(): void
    {
        $browser = self::createClient();

        // TIME_42: PLAYER_WITH_STRIPE solved a puzzle they only borrowed
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/time-added/' . PuzzleSolvingTimeFixture::TIME_42);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#recap-collection-cta');
    }

    public function testNonMemberGetsOneClickFormForSystemCollection(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleIntelligenceFixture::INTEL_TIME_13);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#recap-collection-cta form button[type=submit]');
        $this->assertSelectorNotExists('#recap-collection-cta a[data-turbo-frame="modal-frame"]');

        // The one-click form must really add the puzzle - its field names come from the form type
        $browser->submit($crawler->filter('#recap-collection-cta form')->form());
        $this->assertResponseRedirects();

        $browser->request('GET', '/en/time-added/' . PuzzleIntelligenceFixture::INTEL_TIME_13);
        $this->assertSelectorNotExists('#recap-collection-cta');
    }

    public function testMemberGetsCollectionPickerModalLink(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/time-added/' . PuzzleIntelligenceFixture::INTEL_TIME_11);

        $this->assertResponseIsSuccessful();

        $link = $crawler->filter('#recap-collection-cta a[data-turbo-frame="modal-frame"]');
        self::assertCount(1, $link);
        self::assertStringContainsString('context=recap', (string) $link->attr('href'));
        $this->assertSelectorNotExists('#recap-collection-cta form');
    }
}
