<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\Rating;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PlayerRatingsControllerTest extends WebTestCase
{
    public function testModalFrameRequestShowsRatings(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR has ratings from fixture
        $crawler = $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_REGULAR . '/ratings', [], [], [
            'HTTP_TURBO_FRAME' => 'modal-frame',
        ]);

        $this->assertResponseIsSuccessful();

        // A puzzle link opens the puzzle page - without _top it would load into the modal frame and close the modal
        $puzzleLinks = $crawler->filter('turbo-frame#modal-frame a[href^="/en/puzzle/"]');
        self::assertGreaterThan(0, $puzzleLinks->count());
        $targets = $puzzleLinks->each(static fn (Crawler $link): null|string => $link->attr('data-turbo-frame'));
        self::assertSame(['_top'], array_values(array_unique($targets)));
    }

    public function testModalFrameRequestLoadsForPlayerWithNoRatings(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_PRIVATE . '/ratings', [], [], [
            'HTTP_TURBO_FRAME' => 'modal-frame',
        ]);

        $this->assertResponseIsSuccessful();
    }

    public function testFullPageRequestRedirectsToPlayerProfile(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player/' . PlayerFixture::PLAYER_REGULAR . '/ratings');

        $this->assertResponseRedirects('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
    }
}
