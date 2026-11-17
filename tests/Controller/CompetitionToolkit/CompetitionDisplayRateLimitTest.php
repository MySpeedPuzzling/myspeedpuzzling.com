<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayRateLimit;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\CreatesCompetitionDisplays;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionApiFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * `display_public` (D-displays-overlays.md §9.1, competition toolkit G3): the page, the state and the design of a
 * display link are limited per token and client IP - a screen polling as the runtime does passes, a link hammered from
 * one address gets 429 with Retry-After before anything is read, while the same link from another address (another
 * venue) and another link from the same address go on.
 */
final class CompetitionDisplayRateLimitTest extends WebTestCase
{
    use CreatesCompetitionDisplays;

    public function testNormalPollingPasses(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(CompetitionApiFixture::COMPETITION_API, [['type' => 'timer']]);
        $ip = self::freshIp();

        $browser->request('GET', '/display/' . $token, server: ['REMOTE_ADDR' => $ip]);
        self::assertResponseIsSuccessful();

        // A screen's safety poll and a few signal fetches
        for ($i = 0; $i < 10; $i += 1) {
            $browser->request('GET', '/display/' . $token . '/state', server: ['REMOTE_ADDR' => $ip]);
            self::assertResponseIsSuccessful();
        }

        $browser->request('GET', '/display/' . $token . '/design', server: ['REMOTE_ADDR' => $ip]);
        self::assertResponseIsSuccessful();
    }

    public function testAHammeredLinkGets429WithRetryAfterOnEveryEndpoint(): void
    {
        $browser = self::createClient();
        $token = self::createDisplay(CompetitionApiFixture::COMPETITION_API, [['type' => 'timer']]);
        $other = self::createDisplay(CompetitionApiFixture::COMPETITION_API, [['type' => 'timer']]);
        $ip = self::freshIp();

        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = self::getContainer()->get('limiter.display_public');
        $key = CompetitionDisplayRateLimit::key($token, $ip);
        $limiter->create($key)->consume($limiter->create($key)->consume(0)->getLimit());

        foreach (['', '/state', '/design'] as $path) {
            $browser->request('GET', '/display/' . $token . $path, server: ['REMOTE_ADDR' => $ip]);

            self::assertResponseStatusCodeSame(429, $path);
            $retryAfter = $browser->getResponse()->headers->get('Retry-After');
            self::assertIsString($retryAfter);
            self::assertGreaterThanOrEqual(1, (int) $retryAfter);
            self::assertSame('', (string) $browser->getResponse()->getContent());
        }

        // The same link from another address, another link from this one
        $browser->request('GET', '/display/' . $token . '/state', server: ['REMOTE_ADDR' => self::freshIp()]);
        self::assertResponseIsSuccessful();
        $browser->request('GET', '/display/' . $other . '/state', server: ['REMOTE_ADDR' => $ip]);
        self::assertResponseIsSuccessful();
    }

    /**
     * The limiter's store outlives a test (only the database is rolled back) - an address of its own per test and run
     */
    private static function freshIp(): string
    {
        return sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }
}
