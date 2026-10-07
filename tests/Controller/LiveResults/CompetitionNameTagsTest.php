<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\LiveResults;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\NameTagQrCode;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Printable name tags (docs/features/competitions-management/live-results.md): organisers only; name, flag, #CODE,
 * the table of the first round or of the chosen one, a QR per tag inline.
 */
final class CompetitionNameTagsTest extends WebTestCase
{
    private const string PAGE = '/en/name-tags/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP;

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testOnlyTheEventsOrganisersMayPrintThem(): void
    {
        $this->browser->request('GET', self::PAGE);
        self::assertResponseRedirects();

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
    }

    public function testEveryParticipantGetsATagWithTheTableOfTheirFirstRound(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertSame(
            ['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Eva Noshow', 'Filip Pending', 'Gina Quick', 'Hugo Slow', 'Ivan Last'],
            $this->names($crawler),
        );

        $anna = $crawler->filter('.tag')->eq(0);
        self::assertSame('#ADMIN', trim($anna->filter('.tag-who span')->text()));
        self::assertSame('CZ', $anna->filter('.tag-who img')->attr('alt'));
        // Group A, the first round of hers
        self::assertSame('1', $anna->filter('.tag-table strong')->text());
        self::assertCount(1, $anna->filter('.tag-qr svg'));

        // Filip has no table number in Group A
        self::assertCount(0, $crawler->filter('.tag')->eq(5)->filter('.tag-table'));
        self::assertCount(9, $crawler->filter('.tag-qr svg'));

        // Gina's player keeps her profile private: a badge worn in public shows no #CODE of hers
        $gina = $crawler->filter('.tag')->eq(6);
        self::assertSame('Gina Quick', trim($gina->filter('.tag-name')->text()));
        self::assertCount(0, $gina->filter('.tag-who span'));
        self::assertCount(1, $crawler->filter('[data-first-round-note]'));
    }

    public function testTheWaitlistGetsTagsOnlyWhenTheOrganiserAsks(): void
    {
        self::getContainer()->get(Connection::class)->insert('competition_participant', [
            'id' => Uuid::uuid7()->toString(),
            'name' => 'Wanda Waiting',
            'country' => 'cz',
            'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
            'source' => 'self_joined',
            'registration_status' => 'waitlisted',
            'registered_at' => '2026-01-01 10:00:00',
        ]);
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE);
        self::assertNotContains('Wanda Waiting', $this->names($crawler));
        self::assertSame('Also the 1 person on the waitlist', trim($crawler->filter('input[name="waitlist"]')->closest('label')?->text() ?? ''));

        $crawler = $this->browser->request('GET', self::PAGE . '?waitlist=1');
        self::assertContains('Wanda Waiting', $this->names($crawler));
        self::assertCount(10, $crawler->filter('.tag'));
    }

    /**
     * WJPC scale: the QR codes are drawn once per participant and cached - the sheet of 200 tags shown again (another
     * sort, another round, the print preview) is a page render, not 200 QR drawings (~15 ms each).
     */
    public function testTwoHundredTagsRenderQuicklyOnceTheirCodesAreDrawn(): void
    {
        $database = self::getContainer()->get(Connection::class);

        for ($number = 1; $number <= 191; $number++) {
            $database->insert('competition_participant', [
                'id' => Uuid::uuid7()->toString(),
                'name' => sprintf('Budget Person %03d', $number),
                'country' => 'cz',
                'competition_id' => OfficialResultsFixture::COMPETITION_RESULTS_CUP,
                'source' => 'imported',
            ]);
        }

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->disableReboot();

        // Draws every code once
        $crawler = $this->browser->request('GET', self::PAGE);
        self::assertCount(200, $crawler->filter('.tag-qr svg'));

        $startedAt = hrtime(true);
        $crawler = $this->browser->request('GET', self::PAGE . '?sort=table');
        $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertResponseIsSuccessful();
        self::assertCount(200, $crawler->filter('.tag-qr svg'));
        self::assertLessThan(1500, $milliseconds, sprintf('200 name tags took %d ms with their codes cached', $milliseconds));
    }

    public function testARoundShowsOnlyItsPeopleWithItsTables(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE . '?round=' . OfficialResultsFixture::ROUND_GROUP_B . '&sort=table');

        self::assertSame(['Gina Quick', 'Hugo Slow', 'Ivan Last'], $this->names($crawler));
        self::assertSame(['1', '2', '3'], $crawler->filter('.tag-table strong')->each(static fn (Crawler $node): string => $node->text()));

        // Pairs: the pair's table number, sorted by it (the unnamed pair without one last)
        $crawler = $this->browser->request('GET', self::PAGE . '?round=' . OfficialResultsFixture::ROUND_PAIRS . '&sort=table');

        self::assertSame(['Anna Fast', 'Ben Steady', 'Cara Tied', 'Dan Unfinished', 'Gina Quick', 'Hugo Slow', 'Eva Noshow', 'Filip Pending'], $this->names($crawler));
        self::assertSame(['1', '1', '2', '2', '3', '3'], $crawler->filter('.tag-table strong')->each(static fn (Crawler $node): string => $node->text()));
    }

    public function testARoundOfAnotherEventIsIgnored(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->browser->request('GET', self::PAGE . '?round=018d0005-0000-0000-0000-000000000001');

        self::assertResponseIsSuccessful();
        self::assertCount(9, $crawler->filter('.tag'));
    }

    public function testTheQrLeadsToTheParticipantsScanUrl(): void
    {
        $qr = self::getContainer()->get(NameTagQrCode::class);

        $url = $qr->url(OfficialResultsFixture::COMPETITION_RESULTS_CUP, OfficialResultsFixture::PARTICIPANT_ANNA, 'en');

        self::assertMatchesRegularExpression('~^https?://[^/]+/en/live/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/p/' . OfficialResultsFixture::PARTICIPANT_ANNA . '$~', $url);
        self::assertStringStartsWith('<svg', $qr->svg($url));
    }

    public function testTheParticipantsPageLinksThemForInPersonEventsOnly(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->browser->request('GET', '/en/manage-event-participants/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);
        self::assertCount(1, $crawler->filter('a[href="' . self::PAGE . '"]'));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href^="/en/name-tags/"]'));
    }

    public function testTheParticipantsPageOfAnEventWithoutOfficialResultsKeepsEverythingElse(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $this->browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/en/name-tags/' . CompetitionFixture::COMPETITION_WJPC_2024 . '"]'));
        self::assertCount(1, $crawler->filter('form[action$="/import"], form[enctype="multipart/form-data"]'));
    }

    /**
     * @return list<string>
     */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('.tag-name')->each(static fn (Crawler $node): string => trim($node->text()));
    }
}
