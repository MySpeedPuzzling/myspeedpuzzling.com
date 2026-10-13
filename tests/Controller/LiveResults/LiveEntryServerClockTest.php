<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use DateTimeImmutable;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The live entry's clock (live_results_controller.js): its page and every state answer carry the server's time in
 * milliseconds - `serverNow` has whole seconds only, which let a referee's phone lag the projector by up to a second.
 * The answer with the shortest round trip sets the offset (round_stopwatch_clock.js).
 */
final class LiveEntryServerClockTest extends WebTestCase
{
    public function testThePageAndTheStateCarryTheServersTimeInMilliseconds(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $page = $browser->request('GET', '/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseIsSuccessful();
        $embedded = json_decode($page->filter('script[data-live-results-target="initialState"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($embedded);
        self::assertServerTime($embedded);

        $browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseIsSuccessful();
        $state = json_decode((string) $browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertServerTime($state);
    }

    /**
     * @param array<mixed> $state
     */
    private static function assertServerTime(array $state): void
    {
        self::assertIsInt($state['serverNowMs']);
        self::assertIsString($state['serverNow']);
        // The same moment: the ATOM form is its whole seconds
        self::assertSame(intdiv($state['serverNowMs'], 1000), (int) new DateTimeImmutable($state['serverNow'])->format('U'));
    }
}
