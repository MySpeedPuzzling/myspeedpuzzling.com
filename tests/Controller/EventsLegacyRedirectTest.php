<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Query\GetCompetitionSlugsForSitemap;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The old events page's query parameters (EventsListing Live props) redirect to the new page's state
 * (docs/features/events-page/README.md, "URL parameters and redirects").
 */
final class EventsLegacyRedirectTest extends WebTestCase
{
    public function testPastWithoutScopeGoesToTheNewestArchiveYear(): void
    {
        $browser = self::createClient();
        $newestYear = self::getContainer()->get(GetCompetitionSlugsForSitemap::class)->archiveYears()[0];

        $browser->request('GET', '/en/events?timePeriod=past');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/en/events/archive/' . $newestYear);

        $browser->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testPastWithAnUnknownCountryIsEverywhere(): void
    {
        $browser = self::createClient();
        $newestYear = self::getContainer()->get(GetCompetitionSlugsForSitemap::class)->archiveYears()[0];

        $browser->request('GET', '/de/veranstaltungen?timePeriod=past&country=');

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/de/veranstaltungen/archiv/' . $newestYear);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRedirects(): iterable
    {
        yield 'past in a country: that country (its view shows its past)' => ['/en/events?country=cz&timePeriod=past', '/en/events?country=cz'];
        yield 'past online' => ['/en/events?onlineOnly=1&timePeriod=past', '/en/events?onlineOnly=1'];
        yield 'upcoming dropped, country kept' => ['/en/events?timePeriod=upcoming&country=de', '/en/events?country=de'];
        yield 'live dropped' => ['/en/events?timePeriod=live', '/en/events'];
        yield 'all dropped' => ['/en/events?timePeriod=all', '/en/events'];
        yield 'unknown period dropped' => ['/en/events?timePeriod=whatever&onlineOnly=1', '/en/events?onlineOnly=1'];
        yield 'calendar' => ['/en/events?showCalendar=1', '/en/events?view=calendar'];
        yield 'calendar (boolean spelled out)' => ['/en/events?showCalendar=true', '/en/events?view=calendar'];
        yield 'calendar off dropped' => ['/en/events?showCalendar=0', '/en/events'];
        yield 'calendar with a scope and other parameters' => ['/en/events?country=cz&showCalendar=1&q=valley%20cup', '/en/events?country=cz&q=valley%20cup&view=calendar'];
        yield 'calendar and a past period in a country' => ['/en/events?timePeriod=past&showCalendar=1&country=ca', '/en/events?country=ca&view=calendar'];
        yield 'czech path' => ['/eventy?timePeriod=upcoming', '/eventy'];
        yield 'japanese path stays encoded' => ['/ja/%E3%82%A4%E3%83%99%E3%83%B3%E3%83%88?showCalendar=1', '/ja/%E3%82%A4%E3%83%99%E3%83%B3%E3%83%88?view=calendar'];
    }

    #[DataProvider('provideRedirects')]
    public function testRedirect(string $from, string $to): void
    {
        $browser = self::createClient();

        $browser->request('GET', $from);

        self::assertResponseStatusCodeSame(301);
        self::assertSame($to, $browser->getResponse()->headers->get('Location'));

        $browser->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testCurrentParametersAreNotRedirected(): void
    {
        $browser = self::createClient();

        foreach (['/en/events', '/en/events?country=cz', '/en/events?onlineOnly=1&view=calendar&month=2026-11', '/en/events?q=valley'] as $url) {
            $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
    }
}
