<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pages that used to fall back to the homepage's meta description in base.html.twig.
 */
final class PageMetaDescriptionsTest extends WebTestCase
{
    private const string HOMEPAGE_DESCRIPTION = "Join the world's largest speed puzzling community.";

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function providePages(): iterable
    {
        yield 'puzzle library' => [
            '/en/puzzle-library/' . PlayerFixture::PLAYER_REGULAR,
            "John Doe's puzzle library on MySpeedPuzzling",
        ];

        yield 'favorite puzzlers' => [
            '/en/player-favorites/' . PlayerFixture::PLAYER_WITH_FAVORITES,
            "Michael Johnson's favorite speed puzzlers on MySpeedPuzzling",
        ];

        yield 'activity calendar' => [
            '/en/activity-calendar/' . PlayerFixture::PLAYER_REGULAR,
            "John Doe's puzzling activity calendar on MySpeedPuzzling",
        ];

        yield 'blog' => [
            '/en/blog/2025-02-17/the-biggest-msp-outage',
            'MySpeedPuzzling was down for almost 10 hours on February 17, 2025.',
        ];

        yield 'marketplace how it works' => [
            '/en/marketplace/how-it-works',
            'How the MySpeedPuzzling marketplace works:',
        ];
    }

    /**
     * @param non-empty-string $expectedStart
     */
    #[DataProvider('providePages')]
    public function testPageHasItsOwnMetaDescription(string $path, string $expectedStart): void
    {
        $browser = self::createClient();

        $browser->request('GET', $path);

        $this->assertResponseIsSuccessful();

        $description = $this->metaDescription($browser);
        self::assertStringStartsWith($expectedStart, $description);
        self::assertStringNotContainsString(self::HOMEPAGE_DESCRIPTION, $description);
    }

    public function testDescriptionIsLocalised(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/de/marketplace/wie-es-funktioniert');

        $this->assertResponseIsSuccessful();
        self::assertStringStartsWith('So funktioniert der MySpeedPuzzling-Marktplatz:', $this->metaDescription($browser));
    }

    public function testPrivatePlayersNameStaysOutOfTheHead(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle-library/' . PlayerFixture::PLAYER_PRIVATE);

        $this->assertResponseIsSuccessful();

        $description = $this->metaDescription($browser);
        self::assertStringStartsWith("Hidden Puzzler #PLAYER2's puzzle library", $description);
        self::assertStringNotContainsString('Jane Smith', $description);
    }

    public function testOwnerSeesTheirOwnNameEvenWhenPrivate(): void
    {
        $browser = self::createClient();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('GET', '/en/activity-calendar/' . PlayerFixture::PLAYER_PRIVATE);

        $this->assertResponseIsSuccessful();
        self::assertStringStartsWith("Jane Smith's puzzling activity calendar", $this->metaDescription($browser));
    }

    private function metaDescription(KernelBrowser $browser): string
    {
        return (string) $browser->getCrawler()->filter('meta[name="description"]')->attr('content');
    }
}
