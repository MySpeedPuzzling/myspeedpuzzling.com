<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DataDeletionControllerTest extends WebTestCase
{
    #[DataProvider('locales')]
    public function testPageRendersInEveryLocale(string $locale, string $path): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', $path);

        self::assertResponseIsSuccessful();

        /** @var UrlGeneratorInterface $router */
        $router = self::getContainer()->get(UrlGeneratorInterface::class);
        $content = (string) $browser->getResponse()->getContent();

        self::assertSame($path, $router->generate('data_deletion', ['_locale' => $locale]));
        self::assertCount(1, $crawler->filter('h1'));
        self::assertStringNotContainsString('%edit_profile_link%', $content);
        self::assertStringNotContainsString('%privacy_link%', $content);
        self::assertStringNotContainsString('data_deletion.content', $content);
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href="%s"]', $router->generate('edit_profile', ['_locale' => $locale])))->count());
        self::assertGreaterThan(0, $crawler->filter(sprintf('a[href="%s"]', $router->generate('privacy_policy', ['_locale' => $locale])))->count());
        self::assertGreaterThan(0, $crawler->filter('a[href="mailto:jan@myspeedpuzzling.com"]')->count());
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/data-deletion');

        self::assertResponseIsSuccessful();
    }

    #[DataProvider('locales')]
    public function testPrivacyPolicyLinksToThePage(string $locale, string $path): void
    {
        $browser = self::createClient();

        /** @var UrlGeneratorInterface $router */
        $router = self::getContainer()->get(UrlGeneratorInterface::class);

        $crawler = $browser->request('GET', $router->generate('privacy_policy', ['_locale' => $locale]));

        self::assertResponseIsSuccessful();
        // Once from the policy text, once from the footer
        self::assertGreaterThanOrEqual(2, $crawler->filter(sprintf('a[href="%s"]', $path))->count());
        self::assertStringNotContainsString('%data_deletion_link%', (string) $browser->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locales(): iterable
    {
        yield 'cs' => ['cs', '/smazani-udaju'];
        yield 'en' => ['en', '/en/data-deletion'];
        yield 'es' => ['es', '/es/eliminacion-datos'];
        yield 'ja' => ['ja', '/ja/' . rawurlencode('データ削除')];
        yield 'fr' => ['fr', '/fr/suppression-donnees'];
        yield 'de' => ['de', '/de/datenloeschung'];
    }
}
