<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * official_results/_round_tool_links.html.twig - the one place every organiser page links a round's official results
 * tools: Live entry (live_results), Results (results_desk), Seating (round_seating, in-person events only), and on pages
 * passing the event the round's tab of the participants sheet.
 * Hrefs are compared with the router, so the test holds whichever stream's controller owns the route.
 */
final class RoundToolLinksTest extends KernelTestCase
{
    private const string ROUND = OfficialResultsFixture::ROUND_GROUP_A;

    protected function setUp(): void
    {
        self::bootKernel();
        $request = Request::create('/en/manage-event-rounds/x');
        $request->setLocale('en');
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    public function testAnInPersonRoundGetsAllThreeTools(): void
    {
        $links = $this->render(['round_id' => self::ROUND, 'is_online' => false]);

        self::assertSame([
            'live' => $this->url('live_results'),
            'desk' => $this->url('results_desk'),
            'seating' => $this->url('round_seating'),
        ], $links);
    }

    public function testAnOnlineEventHasNoSeating(): void
    {
        $links = $this->render(['round_id' => self::ROUND, 'is_online' => true]);

        self::assertSame(['live', 'desk'], array_keys($links));
    }

    public function testTheCurrentToolIsLeftOutAndTheReturnAddressPassedOn(): void
    {
        $links = $this->render([
            'round_id' => self::ROUND,
            'is_online' => false,
            'current' => 'desk',
            'return_url' => '/en/manage-event-rounds/x',
            'return_title' => 'Rounds',
        ]);

        self::assertSame(['live', 'seating'], array_keys($links));
        self::assertSame($this->url('live_results', ['return' => '/en/manage-event-rounds/x', 'return_title' => 'Rounds']), $links['live']);
    }

    /**
     * BR17: organiser pages (they pass the event) also link the round's tab of the participants sheet - with the return
     * address. Without the event (the round list has a link of its own) there is none.
     */
    public function testOrganiserPagesLinkTheRoundsTabOfTheParticipantsSheet(): void
    {
        $links = $this->render([
            'round_id' => self::ROUND,
            'is_online' => true,
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'return_url' => '/en/manage-round-results/x',
            'return_title' => 'Results desk',
        ]);

        self::assertSame(['live', 'desk', 'participants'], array_keys($links));
        self::assertSame(
            self::getContainer()->get(UrlGeneratorInterface::class)->generate('participants_sheet', [
                'competitionId' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
                'tab' => self::ROUND,
                'return' => '/en/manage-round-results/x',
                'return_title' => 'Results desk',
                '_locale' => 'en',
            ]),
            $links['participants'],
        );

        $html = self::getContainer()->get(Environment::class)->render('official_results/_round_tool_links.html.twig', [
            'round_id' => self::ROUND,
            'is_online' => true,
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
        ]);
        self::assertSame('Participants', (new Crawler('<div>' . $html . '</div>'))->filter('[data-round-tool="participants"]')->text());
    }

    public function testSeatingSaysNotUsedWhenTheRoundGoesWithoutTableNumbers(): void
    {
        $html = self::getContainer()->get(Environment::class)->render('official_results/_round_tool_links.html.twig', [
            'round_id' => self::ROUND,
            'is_online' => false,
            'table_numbers_off' => true,
        ]);

        $crawler = new Crawler('<div>' . $html . '</div>');
        self::assertSame('not used', $crawler->filter('[data-round-tool="seating"] [data-round-tool-not-used]')->text());
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string> tool => href
     */
    private function render(array $context): array
    {
        $html = self::getContainer()->get(Environment::class)->render('official_results/_round_tool_links.html.twig', $context);
        $crawler = new Crawler('<div>' . $html . '</div>');

        $links = [];
        foreach ($crawler->filter('[data-round-tool]') as $link) {
            \assert($link instanceof \DOMElement);
            $links[$link->getAttribute('data-round-tool')] = $link->getAttribute('href');
        }

        return $links;
    }

    /**
     * @param array<string, string> $query
     */
    private function url(string $route, array $query = []): string
    {
        return self::getContainer()->get(UrlGeneratorInterface::class)->generate($route, ['roundId' => self::ROUND, ...$query, '_locale' => 'en']);
    }
}
