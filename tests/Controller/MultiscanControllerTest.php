<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MultiscanControllerTest extends WebTestCase
{
    public function testGuestIsSentToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/multiscan');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testNonMemberGetsTheTeaserWithoutATray(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/multiscan');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.multiscan-teaser'));
        self::assertCount(0, $crawler->filter('[data-controller="multiscan"]'));
        self::assertCount(1, $crawler->filter('.multiscan-teaser button[data-bs-target="#membersExclusiveModal"]'));
        self::assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (string) $browser->getResponse()->getContent());
    }

    public function testMemberGetsTheScannerAndTheTray(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/multiscan');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="multiscan"]'));
        self::assertCount(1, $crawler->filter('[data-controller="barcode-scanner"][data-barcode-scanner-continuous-value="true"]'));
        self::assertCount(1, $crawler->filter('[data-multiscan-target="tray"]'));
        self::assertCount(1, $crawler->filter('#multiscan-action-add_to_library:checked'));
    }

    public function testEntryPointPresetsTheActionAndAValidatedCollection(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/multiscan?action=return');
        self::assertCount(1, $crawler->filter('#multiscan-action-return:checked'));

        $crawler = $browser->request('GET', '/en/multiscan?action=add_to_library&collection=' . CollectionFixture::COLLECTION_STRIPE_TREFL);
        self::assertCount(1, $crawler->filter('#multiscan-collection option[selected][value="' . CollectionFixture::COLLECTION_STRIPE_TREFL . '"]'));

        // Somebody else's collection is ignored (the system collection stays selected), garbage too
        $crawler = $browser->request('GET', '/en/multiscan?action=add_to_library&collection=' . CollectionFixture::COLLECTION_PRIVATE);
        self::assertCount(0, $crawler->filter('#multiscan-collection option[selected][value="' . CollectionFixture::COLLECTION_PRIVATE . '"]'));
        self::assertCount(1, $crawler->filter('#multiscan-collection option[selected][value="__system_collection__"]'));
        $browser->request('GET', '/en/multiscan?action=nonsense&collection=%00');
        self::assertResponseIsSuccessful();
    }

    #[DataProvider('localePaths')]
    public function testEveryLocalePathIsServed(string $path): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $path);

        self::assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function localePaths(): iterable
    {
        yield 'cs' => ['/multiscan'];
        yield 'en' => ['/en/multiscan'];
        yield 'de' => ['/de/multiscan'];
        yield 'es' => ['/es/multiscan'];
        yield 'fr' => ['/fr/multiscan'];
        yield 'ja' => ['/ja/multiscan'];
    }

    public function testLibraryAndListPagesLinkHere(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/multiscan"]')->count());

        $crawler = $browser->request('GET', '/en/lend-borrow-list/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertGreaterThan(0, $crawler->filter('a[href="/en/multiscan?action=return"]')->count());
    }
}
