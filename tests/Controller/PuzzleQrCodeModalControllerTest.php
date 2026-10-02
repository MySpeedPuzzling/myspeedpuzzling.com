<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PuzzleQrCodeModalControllerTest extends WebTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideLocalizedUrls(): array
    {
        $id = PuzzleFixture::PUZZLE_500_01;

        return [
            'cs' => ['/puzzle/' . $id . '/qr-kod', '/puzzle/' . $id],
            'en' => ['/en/puzzle/' . $id . '/qr-code', '/en/puzzle/' . $id],
            'es' => ['/es/puzzle/' . $id . '/codigo-qr', '/es/puzzle/' . $id],
            'ja' => ['/ja/puzzle/' . $id . '/qr-code', '/ja/' . rawurlencode('パズル') . '/' . $id],
            'fr' => ['/fr/puzzle/' . $id . '/code-qr', '/fr/puzzle/' . $id],
            'de' => ['/de/puzzle/' . $id . '/qr-code', '/de/puzzle/' . $id],
        ];
    }

    /**
     * Crawlers followed the dropdown's link and got a 302 - about 7% of all Googlebot requests.
     */
    #[DataProvider('provideLocalizedUrls')]
    public function testVisitOutsideTheModalRedirectsPermanentlyToThePuzzle(string $modalUrl, string $puzzleUrl): void
    {
        $browser = self::createClient();

        $browser->request('GET', $modalUrl);

        $this->assertResponseRedirects($puzzleUrl, Response::HTTP_MOVED_PERMANENTLY);
    }

    public function testModalIsServedToTheModalFrameAndKeptOutOfTheIndex(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '/qr-code', server: ['HTTP_TURBO_FRAME' => 'modal-frame']);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString(
            '/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '/qr-code.png',
            (string) $browser->getResponse()->getContent(),
        );
    }

    public function testDropdownLinkToTheModalIsNofollow(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        // The ⋯ menu is on the page twice - in the header and in the bar that replaces it on scroll
        $links = $crawler->filter('a[href="/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '/qr-code"]');
        self::assertCount(2, $links);
        self::assertSame(['nofollow', 'nofollow'], $links->extract(['rel']));
    }
}
