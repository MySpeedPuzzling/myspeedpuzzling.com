<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CollectionDetailControllerTest extends WebTestCase
{
    public function testAnonymousUserCanAccessPage(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);

        $this->assertResponseIsSuccessful();
    }

    public function testOwnCollectionPagesLinkToThePuzzlePicker(): void
    {
        $browser = self::createClient();

        // Guest: no button
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.puzzle-picker-collection-link'));

        // Member on her own custom collection: the picker with this very collection preselected
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PUBLIC);
        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('.puzzle-picker-collection-link');
        self::assertCount(1, $link);
        self::assertSame('/en/what-to-solve-next?collections%5B0%5D=' . CollectionFixture::COLLECTION_PUBLIC, $link->attr('href'));
        self::assertSame('nofollow', $link->attr('rel'));
        self::assertStringContainsString('Pick from this collection', $link->text());

        // ... and on her system collection: the sentinel id
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_WITH_STRIPE);
        $this->assertResponseIsSuccessful();
        self::assertSame('/en/what-to-solve-next?collections%5B0%5D=' . rawurlencode(Collection::SYSTEM_ID), $crawler->filter('.puzzle-picker-collection-link')->attr('href'));

        // Somebody else's collection: no button
        $crawler = $browser->request('GET', '/en/puzzle-collection/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.puzzle-picker-collection-link'));

        // Non-member on his own collection: specific collections are members-only, so the whole shelf
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/collection/' . CollectionFixture::COLLECTION_PRIVATE);
        $this->assertResponseIsSuccessful();
        self::assertSame('/en/what-to-solve-next?source=mine', $crawler->filter('.puzzle-picker-collection-link')->attr('href'));

        // The deep link really lands on a picker restricted to that collection
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/what-to-solve-next?collections%5B0%5D=' . CollectionFixture::COLLECTION_STRIPE_TREFL);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('of 3 matching puzzles', (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('.puzzle-picker-filter-bar a[title="Remove this filter"]:contains("My Trefl Collection")'));
    }
}
