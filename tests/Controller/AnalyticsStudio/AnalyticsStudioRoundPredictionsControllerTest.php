<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Message\WarmAnalyticsStudioPredictions;
use SpeedPuzzling\Web\MessageHandler\WarmAnalyticsStudioPredictionsHandler;
use SpeedPuzzling\Web\Tests\AnalyticsStudioTesting;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture as F;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The analytics studio's round-wide predictions (analytics-studio.md R28-R33) on the "Results Cup": Group B (solo -
 * Gina private, Hugo public, Ivan unlinked) and the pairs round. `warming` sends one warm a minute, `ready` once the
 * warm ran, the values equal the entrant detail's, an opt-out shows at once, a pair round is `none` and asks nothing.
 */
final class AnalyticsStudioRoundPredictionsControllerTest extends WebTestCase
{
    use AnalyticsStudioTesting;
    use QueryCountAssertions;

    private const string URL = '/analytics-studio/' . F::ROUND_GROUP_B . '/round-predictions';

    public function testWarmingThenReadyWithTheDetailsNumbers(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();

        $warming = $this->studioJson($browser, self::URL . '?v=whatever');
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        self::assertSame('noindex, nofollow', $browser->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame('warming', self::dig($warming, 'status'));
        self::assertSame([F::ENTRY_B_GINA, F::ENTRY_B_HUGO, F::ENTRY_B_IVAN], array_keys(self::digArray($warming, 'entrants')));
        self::assertSame([[]], self::dig($warming, 'entrants', F::ENTRY_B_GINA, 'members'), 'private = unlinked');
        self::assertSame([], self::dig($warming, 'entrants', F::ENTRY_B_HUGO, 'members'), 'not cached yet = absent');
        self::assertSame([[]], self::dig($warming, 'entrants', F::ENTRY_B_IVAN, 'members'));
        $warms = $this->warmsSent($browser);
        self::assertCount(1, $warms);
        $warm = $warms[0];
        self::assertSame([F::ROUND_GROUP_B, self::dig($warming, 'visibleHash'), 0], [$warm->roundId, $warm->visibleHash, $warm->turn]);

        // Within the minute: still warming, nothing sent again
        self::assertSame('warming', self::dig($this->studioJson($browser, self::URL), 'status'));
        self::assertSame([], $this->warmsSent($browser));

        $handler = $browser->getContainer()->get(WarmAnalyticsStudioPredictionsHandler::class);
        $handler($warm);

        $this->startCountingQueries($browser);
        $ready = $this->studioJson($browser, self::URL);
        // The round, its visible puzzles, the entrants - the predictions come from the cache in one multi-get
        self::assertSame(3, $this->queryCount($browser));
        self::assertSame('ready', self::dig($ready, 'status'));
        self::assertSame([], $this->warmsSent($browser));
        self::assertFalse(self::dig($ready, 'entrants', F::ENTRY_B_HUGO, 'members', 0, 'predictionsOptedOut'));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, (string) $browser->getResponse()->getContent());

        $entrant = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_GROUP_B . '/entrant/' . F::ENTRY_B_HUGO);
        self::assertSame(self::dig($entrant, 'members', 0, 'prediction'), self::dig($ready, 'entrants', F::ENTRY_B_HUGO, 'members', 0, 'prediction'), 'the column is the detail');

        // An opt-out takes effect at once (the entrants are read on every answer)
        $this->studioDatabase()->executeStatement('UPDATE player SET time_predictions_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        $optedOut = $this->studioJson($browser, self::URL);
        self::assertSame('ready', self::dig($optedOut, 'status'));
        self::assertSame([['predictionsOptedOut' => true, 'prediction' => null]], self::dig($optedOut, 'entrants', F::ENTRY_B_HUGO, 'members'));
    }

    public function testAPairRoundIsNoneAndAsksForNothing(): void
    {
        $browser = self::createClient();
        $this->clearAnalyticsStudioCache();

        $this->startCountingQueries($browser);
        $none = $this->studioJson($browser, '/analytics-studio/' . F::ROUND_PAIRS . '/round-predictions');

        self::assertSame('none', self::dig($none, 'status'));
        self::assertSame([], self::dig($none, 'entrants'));
        self::assertStringContainsString('"entrants":{}', (string) $browser->getResponse()->getContent(), 'entrants is an object');
        self::assertSame(2, $this->queryCount($browser));
        self::assertSame([], $this->warmsSent($browser));
    }

    public function testNothingBeforeAReveal(): void
    {
        $browser = self::createClient();
        $this->studioDatabase()->executeStatement("UPDATE competition_round_puzzle SET hide_until_round_starts = true, hide_mode = 'entirely', reveal_mode = 'manual', reveal_at = NULL WHERE round_id = :round", ['round' => F::ROUND_GROUP_B]);
        $this->clearAnalyticsStudioCache();

        self::assertSame('none', self::dig($this->studioJson($browser, self::URL), 'status'));
        self::assertSame([], $this->warmsSent($browser));
    }

    public function testUnknownRoundsAre404(): void
    {
        $browser = self::createClient();

        $this->studioJson($browser, '/analytics-studio/018d0020-0000-0000-0000-00000000ffff/round-predictions', 404);
        $browser->request('GET', '/analytics-studio/not-a-round/round-predictions');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheHeavyLimitApplies(): void
    {
        $browser = self::createClient();
        $address = '203.0.113.91';
        self::getContainer()->get('limiter.analytics_studio_heavy')->create($address)->consume(100000);

        $browser->request('GET', self::URL, server: ['REMOTE_ADDR' => $address]);

        self::assertResponseStatusCodeSame(429);
        self::assertTrue($browser->getResponse()->headers->has('Retry-After'));
    }

    /**
     * The warms the last request sent (the analytics_studio queue, in memory in tests).
     *
     * @return list<WarmAnalyticsStudioPredictions>
     */
    private function warmsSent(KernelBrowser $browser): array
    {
        $transport = $browser->getContainer()->get('messenger.transport.analytics_studio');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $warms = [];

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof WarmAnalyticsStudioPredictions) {
                $warms[] = $message;
            }
        }

        return $warms;
    }
}
